<?php

namespace App\Services;

use App\Events\AmbulanceUpdated;
use App\Events\CrewStateChanged;
use App\Events\IncidentUpdated;
use App\Jobs\ExpireOffer;
use App\Models\Ambulance;
use App\Models\Dispatch;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\CrewAlert;
use App\Notifications\StaffAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The dispatch brain.
 *
 * A unit is ELIGIBLE only if ALL of these are true (this is how availability is identified and verified):
 *   1. status = available            (not on a call, not reserved by another offer, not out of service)
 *   2. at least one crew member is on duty
 *   3. its GPS was updated within DISPATCH_GPS_FRESH_SECONDS (so the position is real, not stale)
 * Eligible units are ranked by ROAD travel time to the patient (OSRM), with a small preference for the
 * unit based in the patient's barangay. The offer is atomic (row locks), so two incidents can never
 * grab the same ambulance.
 *
 * Offer -> crew has 60 s to accept -> if declined / no answer, the next best unit is offered automatically.
 */
class DispatchService
{
    private const TRANSITIONS = [
        'en_route' => ['on_scene'],
        'on_scene' => ['transporting', 'completed'],
        'transporting' => ['at_hospital'],
        'at_hospital' => ['completed'],
    ];

    public function __construct(private RoutingService $routing)
    {
    }

    // ------------------------------------------------------------------ ranking

    /** @return Collection<int, array{ambulance: Ambulance, eta_seconds: int, distance_m: int, source: string, is_home_unit: bool, rank: int}> */
    public function candidates(float $lat, float $lng, ?int $barangayId = null, array $excludeIds = []): Collection
    {
        $ambulances = Ambulance::query()
            ->where('status', Ambulance::AVAILABLE)
            ->whereNotNull('last_lat')->whereNotNull('last_lng')
            ->where('last_seen_at', '>=', now()->subSeconds(config('dispatch.gps_fresh_seconds')))
            ->whereHas('crew', fn ($q) => $q->where('on_duty', true))
            ->when($excludeIds, fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->with(['homeBarangay', 'crew'])
            ->get()->values();

        if ($ambulances->isEmpty()) {
            return collect();
        }

        $etas = $this->routing->etas($ambulances->map(fn ($a) => [$a->last_lat, $a->last_lng])->all(), $lat, $lng);
        $bonus = config('dispatch.home_unit_bonus_seconds');

        return $ambulances->map(function (Ambulance $a, int $i) use ($etas, $barangayId, $bonus) {
            $home = $barangayId !== null && (int) $a->home_barangay_id === $barangayId;

            return [
                'ambulance' => $a,
                'eta_seconds' => $etas[$i]['eta_seconds'],
                'distance_m' => $etas[$i]['distance_m'],
                'source' => $etas[$i]['source'],
                'is_home_unit' => $home,
                'score' => $etas[$i]['eta_seconds'] - ($home ? $bonus : 0),
            ];
        })->sortBy('score')->values()->map(fn ($c, $i) => $c + ['rank' => $i + 1]);
    }

    public function candidatePayloads(Collection $candidates): array
    {
        return $candidates->map(fn ($c) => [
            'rank' => $c['rank'],
            'ambulance' => $c['ambulance']->toApi(),
            'eta_seconds' => $c['eta_seconds'],
            'distance_m' => $c['distance_m'],
            'source' => $c['source'],
            'is_home_unit' => $c['is_home_unit'],
        ])->values()->all();
    }

    // ------------------------------------------------------------------ incident lifecycle

    public function createIncident(array $data, User $by, ?int $ambulanceId = null): Incident
    {
        $incident = Incident::create([
            'caller_name' => $data['caller_name'],
            'caller_phone' => $data['caller_phone'],
            'barangay_id' => $data['barangay_id'],
            'address' => $data['address'] ?? null,
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'emergency_type' => $data['emergency_type'],
            'priority' => $data['priority'],
            'notes' => $data['notes'] ?? null,
            'status' => Incident::AWAITING_UNIT,
            'created_by' => $by->id,
        ])->load('barangay');

        $this->notifyStaff(new StaffAlert(
            'New incident: '.$this->label($incident->emergency_type),
            'Brgy. '.$incident->barangay->name.' | '.ucfirst($incident->priority).' priority',
            $incident->id,
            in_array($incident->priority, ['high', 'critical'], true) ? 'critical' : 'info',
        ));
        event(new IncidentUpdated($incident));

        $offer = null;
        if ($ambulanceId) {
            $ambulance = Ambulance::find($ambulanceId);
            $offer = $ambulance ? $this->offer($incident, $ambulance) : null;
        }
        if (! $offer) {
            // No unit chosen, or the chosen one was taken a moment ago: pick the nearest possible.
            $this->escalate($incident, $ambulanceId ? 'The selected unit became unavailable.' : null);
        }

        return $incident->fresh(['barangay', 'ambulance', 'activeDispatch.ambulance']);
    }

    /** Manually (re)assign an open incident to a chosen ambulance. */
    public function reassign(Incident $incident, Ambulance $ambulance): ?Dispatch
    {
        $this->releaseLiveDispatch($incident, 'Reassigned by the dispatcher.');
        $incident->refresh();

        return $this->offer($incident, $ambulance);
    }

    public function cancelIncident(Incident $incident): void
    {
        $this->releaseLiveDispatch($incident, 'Incident cancelled by the dispatcher.');
        $incident->update(['status' => Incident::CANCELLED, 'closed_at' => now(), 'assigned_ambulance_id' => null]);
        $this->notifyStaff(new StaffAlert('Incident #'.$incident->id.' cancelled', 'Brgy. '.$incident->barangay->name, $incident->id, 'warning'));
        event(new IncidentUpdated($incident));
    }

    // ------------------------------------------------------------------ offers

    /** Send the emergency to one ambulance. Returns null if that unit is no longer available. */
    public function offer(Incident $incident, Ambulance $ambulance, ?array $meta = null): ?Dispatch
    {
        if ($meta === null) {
            $eta = $ambulance->last_lat !== null
                ? $this->routing->etas([[$ambulance->last_lat, $ambulance->last_lng]], $incident->lat, $incident->lng)[0]
                : ['eta_seconds' => null, 'distance_m' => null, 'source' => null];
            $meta = ['eta_seconds' => $eta['eta_seconds'], 'distance_m' => $eta['distance_m'], 'source' => $eta['source'], 'rank' => null];
        }
        $ttl = (int) config('dispatch.offer_ttl_seconds');

        $dispatch = DB::transaction(function () use ($incident, $ambulance, $meta, $ttl) {
            $amb = Ambulance::whereKey($ambulance->id)->lockForUpdate()->first();
            $inc = Incident::whereKey($incident->id)->lockForUpdate()->first();
            if (! $amb || $amb->status !== Ambulance::AVAILABLE || ! $inc || $inc->status !== Incident::AWAITING_UNIT) {
                return null;
            }

            $d = Dispatch::create([
                'incident_id' => $inc->id,
                'ambulance_id' => $amb->id,
                'status' => Dispatch::OFFERED,
                'rank' => $meta['rank'] ?? null,
                'eta_seconds' => $meta['eta_seconds'] ?? null,
                'distance_m' => $meta['distance_m'] ?? null,
                'eta_source' => $meta['source'] ?? null,
                'offered_at' => now(),
                'expires_at' => now()->addSeconds($ttl),
            ]);
            $amb->update(['status' => Ambulance::DISPATCHED]);
            $inc->update(['status' => Incident::OFFERED, 'assigned_ambulance_id' => $amb->id]);

            return $d;
        });

        if (! $dispatch) {
            return null;
        }

        ExpireOffer::dispatch($dispatch->id)->delay(now()->addSeconds($ttl + 2));

        $incident->refresh()->load('barangay');
        $ambulance->refresh();
        $this->notifyCrew($ambulance, new CrewAlert(
            'Emergency: '.$this->label($incident->emergency_type),
            'Brgy. '.$incident->barangay->name.'. Open the app and accept within '.$ttl.' seconds.',
            true,
            $incident->id,
            $dispatch->id,
        ));
        event(new CrewStateChanged($ambulance->id, 'offer'));
        $this->broadcast($incident, $ambulance);

        return $dispatch;
    }

    public function accept(Dispatch $dispatch): Dispatch
    {
        DB::transaction(function () use ($dispatch) {
            $d = Dispatch::whereKey($dispatch->id)->lockForUpdate()->first();
            if (! $d || $d->status !== Dispatch::OFFERED) {
                abort(409, 'This call is no longer available. It may have been reassigned.');
            }
            if ($d->expires_at && $d->expires_at->lt(now()->subSeconds(3))) {
                abort(409, 'The '.config('dispatch.offer_ttl_seconds').'-second window ended. The call was passed to the next unit.');
            }
            $d->update(['status' => Dispatch::ACCEPTED, 'stage' => 'en_route', 'responded_at' => now()]);
            Ambulance::whereKey($d->ambulance_id)->update(['status' => Ambulance::EN_ROUTE]);
            Incident::whereKey($d->incident_id)->update(['status' => Incident::EN_ROUTE]);
        });

        $dispatch->refresh()->load(['incident.barangay', 'ambulance', 'hospital']);
        $this->refreshRoute($dispatch);

        $eta = $dispatch->route_eta_seconds ?? $dispatch->eta_seconds;
        $this->notifyStaff(new StaffAlert(
            $dispatch->ambulance->name.' accepted',
            'Incident #'.$dispatch->incident_id.($eta ? ' | ETA '.max(1, (int) round($eta / 60)).' min' : ''),
            $dispatch->incident_id,
            'success',
        ));
        event(new CrewStateChanged($dispatch->ambulance_id, 'accepted'));
        $this->broadcast($dispatch->incident, $dispatch->ambulance);

        return $dispatch;
    }

    public function decline(Dispatch $dispatch): void
    {
        $this->closeOffer($dispatch, Dispatch::DECLINED);
    }

    public function expire(Dispatch $dispatch): void
    {
        $this->closeOffer($dispatch, Dispatch::EXPIRED);
    }

    private function closeOffer(Dispatch $dispatch, string $newStatus): void
    {
        $closed = DB::transaction(function () use ($dispatch, $newStatus) {
            $d = Dispatch::whereKey($dispatch->id)->lockForUpdate()->first();
            if (! $d || $d->status !== Dispatch::OFFERED) {
                return null;
            }
            $d->update(['status' => $newStatus, 'responded_at' => now()]);
            Ambulance::whereKey($d->ambulance_id)->where('status', Ambulance::DISPATCHED)->update(['status' => Ambulance::AVAILABLE]);
            Incident::whereKey($d->incident_id)->update(['status' => Incident::AWAITING_UNIT, 'assigned_ambulance_id' => null]);

            return $d;
        });

        if (! $closed) {
            return;
        }

        $closed->load(['incident.barangay', 'ambulance']);
        $reason = $newStatus === Dispatch::DECLINED
            ? $closed->ambulance->name.' declined.'
            : 'No response from '.$closed->ambulance->name.' in '.config('dispatch.offer_ttl_seconds').' seconds.';

        if ($newStatus === Dispatch::EXPIRED) {
            $this->notifyCrew($closed->ambulance, new CrewAlert('Offer expired', 'The call was passed to another unit.', false, $closed->incident_id, $closed->id));
        }
        event(new CrewStateChanged($closed->ambulance_id, $newStatus));
        $this->broadcast($closed->incident, $closed->ambulance);
        $this->escalate($closed->incident, $reason);
    }

    /**
     * Offer to the next best unit that has not been tried yet. If nobody is left, alert the dispatchers.
     */
    public function escalate(Incident $incident, ?string $reason = null): ?Dispatch
    {
        $incident->refresh()->load('barangay');
        if ($incident->status !== Incident::AWAITING_UNIT) {
            return null;
        }

        $tried = $incident->dispatches()->pluck('ambulance_id')->all();
        if (config('dispatch.auto_escalate') && count($tried) < config('dispatch.max_offers')) {
            foreach ($this->candidates($incident->lat, $incident->lng, $incident->barangay_id, $tried) as $c) {
                $dispatch = $this->offer($incident, $c['ambulance'], $c);
                if ($dispatch) {
                    $this->notifyStaff(new StaffAlert(
                        'Offered to '.$c['ambulance']->name,
                        trim(($reason ? $reason.' ' : '').'ETA '.max(1, (int) round($c['eta_seconds'] / 60)).' min.'),
                        $incident->id,
                        'info',
                    ));

                    return $dispatch;
                }
            }
        }

        $this->notifyStaff(new StaffAlert(
            'No ambulance available',
            trim(($reason ? $reason.' ' : '').'Incident #'.$incident->id.' (Brgy. '.$incident->barangay->name.') needs a manual assignment or help from a neighboring LGU.'),
            $incident->id,
            'critical',
        ));
        event(new IncidentUpdated($incident));

        return null;
    }

    // ------------------------------------------------------------------ trip progress

    public function advance(Dispatch $dispatch, string $to, ?int $hospitalId = null): Dispatch
    {
        DB::transaction(function () use ($dispatch, $to, $hospitalId) {
            $d = Dispatch::whereKey($dispatch->id)->lockForUpdate()->first();
            if (! $d || $d->status !== Dispatch::ACCEPTED) {
                abort(409, 'This trip is not active.');
            }
            if (! in_array($to, self::TRANSITIONS[$d->stage] ?? [], true)) {
                abort(422, 'Cannot move from "'.$d->stage.'" to "'.$to.'".');
            }
            if ($to === 'transporting' && ! $hospitalId) {
                abort(422, 'Choose the destination hospital first.');
            }

            $values = ['stage' => $to];
            if ($to === 'on_scene') {
                $values['arrived_at'] = now();
            }
            if ($to === 'transporting') {
                $values['hospital_id'] = $hospitalId;
            }
            if ($to === 'completed') {
                $values += ['status' => Dispatch::COMPLETED, 'completed_at' => now(), 'route_geojson' => null, 'route_eta_seconds' => null, 'route_distance_m' => null];
                Incident::whereKey($d->incident_id)->update(['status' => Incident::COMPLETED, 'closed_at' => now()]);
                Ambulance::whereKey($d->ambulance_id)->update(['status' => Ambulance::AVAILABLE]);
            } else {
                Incident::whereKey($d->incident_id)->update(['status' => $to]);
                Ambulance::whereKey($d->ambulance_id)->update(['status' => $to]);
            }
            $d->update($values);
        });

        $dispatch->refresh()->load(['incident.barangay', 'ambulance', 'hospital']);
        $this->refreshRoute($dispatch);

        $text = [
            'on_scene' => 'is on scene',
            'transporting' => 'is transporting the patient'.($dispatch->hospital ? ' to '.$dispatch->hospital->name : ''),
            'at_hospital' => 'arrived at the hospital',
            'completed' => 'completed the incident and is available again',
        ][$to];
        $this->notifyStaff(new StaffAlert($dispatch->ambulance->name.' '.$text, 'Incident #'.$dispatch->incident_id, $dispatch->incident_id, $to === 'completed' ? 'success' : 'info'));
        event(new CrewStateChanged($dispatch->ambulance_id, $to));
        $this->broadcast($dispatch->incident, $dispatch->ambulance);

        return $dispatch;
    }

    // ------------------------------------------------------------------ GPS + routes

    public function updateLocation(Ambulance $ambulance, array $data): Ambulance
    {
        $ambulance->update([
            'last_lat' => $data['lat'],
            'last_lng' => $data['lng'],
            'last_accuracy' => $data['accuracy'] ?? null,
            'last_seen_at' => now(),
        ]);

        $last = $ambulance->locationLogs()->latest('recorded_at')->first();
        if (! $last || $last->recorded_at->lte(now()->subSeconds(10))) {
            $ambulance->locationLogs()->create([
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'accuracy' => $data['accuracy'] ?? null,
                'speed' => $data['speed'] ?? null,
                'recorded_at' => now(),
            ]);
        }

        event(new AmbulanceUpdated($ambulance));

        // Keep the route fresh while driving to the patient or to the hospital.
        $dispatch = Dispatch::with(['incident', 'hospital', 'ambulance'])
            ->where('ambulance_id', $ambulance->id)
            ->where('status', Dispatch::ACCEPTED)
            ->whereIn('stage', ['en_route', 'transporting'])
            ->first();
        if ($dispatch && (! $dispatch->route_updated_at || $dispatch->route_updated_at->lte(now()->subSeconds(config('dispatch.route_refresh_seconds'))))) {
            $this->refreshRoute($dispatch);
            event(new IncidentUpdated($dispatch->incident));
            event(new CrewStateChanged($ambulance->id, 'route'));
        }

        return $ambulance;
    }

    /** Destination of the current stage, or null when no driving is needed (on scene / at hospital). */
    private function destination(Dispatch $d): ?array
    {
        return match ($d->stage) {
            'en_route' => [$d->incident->lat, $d->incident->lng],
            'transporting' => $d->hospital ? [$d->hospital->latitude, $d->hospital->longitude] : null,
            default => null,
        };
    }

    public function refreshRoute(Dispatch $d): void
    {
        $d->loadMissing(['incident', 'ambulance', 'hospital']);
        $dest = $d->status === Dispatch::ACCEPTED ? $this->destination($d) : null;
        $a = $d->ambulance;

        if (! $dest || $a->last_lat === null) {
            $d->update(['route_geojson' => null, 'route_eta_seconds' => null, 'route_distance_m' => null, 'route_updated_at' => now()]);

            return;
        }

        $r = $this->routing->route($a->last_lat, $a->last_lng, $dest[0], $dest[1]);
        $d->update([
            'route_geojson' => $r['geometry'],
            'route_eta_seconds' => $r['duration'],
            'route_distance_m' => $r['distance'],
            'route_updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ duty

    public function setDuty(User $user, bool $onDuty): User
    {
        $ambulance = $user->ambulance;
        if (! $ambulance) {
            abort(422, 'This account is not linked to an ambulance.');
        }

        if ($onDuty) {
            if ($ambulance->status === Ambulance::OUT_OF_SERVICE) {
                abort(422, 'This ambulance is marked out of service. Contact the admin.');
            }
            $user->update(['on_duty' => true]);
            if ($ambulance->status === Ambulance::OFFLINE) {
                $ambulance->update(['status' => Ambulance::AVAILABLE]);
            }
        } else {
            if (in_array($ambulance->status, Ambulance::BUSY, true)) {
                abort(422, 'Finish the current incident before going off duty.');
            }
            $user->update(['on_duty' => false]);
            if ($ambulance->status === Ambulance::AVAILABLE && ! $ambulance->crew()->where('on_duty', true)->exists()) {
                $ambulance->update(['status' => Ambulance::OFFLINE]);
            }
        }

        event(new AmbulanceUpdated($ambulance->fresh()));

        return $user->fresh();
    }

    // ------------------------------------------------------------------ helpers

    /** Close any live offer/trip of an incident and free its ambulance (used by cancel and reassign). */
    private function releaseLiveDispatch(Incident $incident, string $message): void
    {
        $live = $incident->dispatches()->whereIn('status', [Dispatch::OFFERED, Dispatch::ACCEPTED])->with('ambulance')->get();
        foreach ($live as $d) {
            $d->update(['status' => Dispatch::CANCELLED, 'responded_at' => now(), 'route_geojson' => null]);
            $amb = $d->ambulance;
            if ($amb && in_array($amb->status, Ambulance::BUSY, true)) {
                $amb->update(['status' => Ambulance::AVAILABLE]);
            }
            if ($amb) {
                $this->notifyCrew($amb, new CrewAlert('Call cancelled', $message, true, $incident->id, $d->id));
                event(new CrewStateChanged($amb->id, 'cancelled'));
                event(new AmbulanceUpdated($amb->fresh()));
            }
        }
        if ($live->isNotEmpty()) {
            $incident->update(['status' => Incident::AWAITING_UNIT, 'assigned_ambulance_id' => null]);
        }
    }

    private function broadcast(Incident $incident, ?Ambulance $ambulance): void
    {
        event(new IncidentUpdated($incident->fresh(['barangay', 'ambulance', 'activeDispatch.ambulance'])));
        if ($ambulance) {
            event(new AmbulanceUpdated($ambulance->fresh()));
        }
    }

    private function notifyStaff(StaffAlert $alert): void
    {
        Notification::send(User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_DISPATCHER])->get(), $alert);
    }

    private function notifyCrew(Ambulance $ambulance, CrewAlert $alert): void
    {
        Notification::send($ambulance->crew()->where('on_duty', true)->get(), $alert);
    }

    private function label(string $type): string
    {
        return ucfirst(str_replace('_', ' ', $type));
    }
}
