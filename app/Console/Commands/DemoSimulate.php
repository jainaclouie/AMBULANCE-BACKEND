<?php

namespace App\Console\Commands;

use App\Models\Ambulance;
use App\Models\Dispatch;
use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Console\Command;

/**
 * DEMO ONLY. Pretends to be the crew phones: sends GPS positions so the live map moves without real phones.
 * Real deployments use the crew app's GPS instead (see README).
 */
class DemoSimulate extends Command
{
    protected $signature = 'demo:simulate
        {--activate : Put every road ambulance on duty with a starting GPS position near its home barangay}
        {--tick=4 : Seconds between GPS updates}
        {--steps=4 : Route points moved per update while driving}
        {--once : Send one update and exit}';

    protected $description = 'DEMO ONLY: simulate crew phone GPS so the map moves.';

    public function handle(DispatchService $service): int
    {
        if ($this->option('activate')) {
            $this->activate($service);
        }

        $state = [];
        $this->info('Simulating GPS. Press Ctrl+C to stop.');
        do {
            $ambulances = Ambulance::with('crew')->whereNotIn('status', [Ambulance::OUT_OF_SERVICE, Ambulance::OFFLINE])->get();
            foreach ($ambulances as $a) {
                if (! $a->crew->contains('on_duty', true) || $a->last_lat === null) {
                    continue;
                }
                [$lat, $lng] = $this->nextPosition($a, $state, (int) $this->option('steps'));
                $service->updateLocation($a, ['lat' => $lat, 'lng' => $lng, 'accuracy' => 8]);
            }
            if ($this->option('once')) {
                break;
            }
            sleep(max(1, (int) $this->option('tick')));
        } while (true);

        return self::SUCCESS;
    }

    private function activate(DispatchService $service): void
    {
        foreach (Ambulance::with('homeBarangay')->get() as $a) {
            if ($a->status === Ambulance::OUT_OF_SERVICE || $a->homeBarangay?->is_island) {
                continue; // the island unit has no road route; leave it offline
            }
            $a->update([
                'status' => Ambulance::AVAILABLE,
                'last_lat' => ($a->homeBarangay?->latitude ?? 10.2555) + mt_rand(-8, 8) / 10000,
                'last_lng' => ($a->homeBarangay?->longitude ?? 123.948) + mt_rand(-8, 8) / 10000,
                'last_seen_at' => now(),
            ]);
            User::where('ambulance_id', $a->id)->update(['on_duty' => true]);
        }
        $this->info('Ambulances activated.');
    }

    /** @return array{0: float, 1: float} */
    private function nextPosition(Ambulance $a, array &$state, int $steps): array
    {
        $dispatch = Dispatch::where('ambulance_id', $a->id)->where('status', Dispatch::ACCEPTED)
            ->whereIn('stage', ['en_route', 'transporting'])->whereNotNull('route_geojson')->first();

        if ($dispatch) {
            $coords = $dispatch->route_geojson['coordinates'] ?? [];
            $key = $dispatch->id.'|'.$dispatch->route_updated_at?->timestamp;
            if (($state[$a->id]['key'] ?? null) !== $key) {
                $state[$a->id] = ['key' => $key, 'i' => 0];
            }
            if (count($coords) > 0) {
                $state[$a->id]['i'] = min(count($coords) - 1, $state[$a->id]['i'] + max(1, $steps));
                $p = $coords[$state[$a->id]['i']];

                return [(float) $p[1], (float) $p[0]];
            }
        }

        // Parked / idle: tiny GPS jitter.
        return [$a->last_lat + mt_rand(-3, 3) / 100000, $a->last_lng + mt_rand(-3, 3) / 100000];
    }
}
