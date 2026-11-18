<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Models\Incident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Summary numbers for the Reports page: how many calls, how fast the response was,
 * where calls come from, and how each ambulance performed.
 */
class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $range = $request->validate(['days' => 'nullable|in:7,30,90,all'])['days'] ?? '30';
        $since = $range === 'all' ? null : now()->subDays((int) $range)->startOfDay();

        $incidents = Incident::with(['barangay', 'dispatches.ambulance'])
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->latest()->get();

        $dispatches = $incidents->flatMap->dispatches;
        $trips = $dispatches->filter(fn (Dispatch $d) => in_array($d->status, [Dispatch::ACCEPTED, Dispatch::COMPLETED], true));

        // Response time = from the moment the incident was logged until the ambulance reached the scene.
        $responseOf = function (Incident $incident): ?float {
            $arrived = $incident->dispatches->first(fn (Dispatch $d) => $d->arrived_at !== null);

            return $arrived ? max(0, $arrived->arrived_at->timestamp - $incident->created_at->timestamp) / 60 : null;
        };
        $responses = $incidents->map($responseOf)->filter(fn ($v) => $v !== null);

        $acceptSeconds = $trips->filter(fn (Dispatch $d) => $d->offered_at && $d->responded_at)
            ->map(fn (Dispatch $d) => max(0, $d->responded_at->timestamp - $d->offered_at->timestamp));

        $count = fn (Collection $items) => $items->map(fn ($n, $label) => ['label' => (string) $label, 'count' => $n])->values()->all();

        return response()->json([
            'days' => $range,
            'totals' => [
                'total' => $incidents->count(),
                'completed' => $incidents->where('status', Incident::COMPLETED)->count(),
                'cancelled' => $incidents->where('status', Incident::CANCELLED)->count(),
                'open' => $incidents->whereIn('status', Incident::OPEN)->count(),
                'escalations' => $dispatches->whereIn('status', [Dispatch::DECLINED, Dispatch::EXPIRED])->count(),
            ],
            'avg_response_minutes' => $responses->isEmpty() ? null : round($responses->avg(), 1),
            'avg_accept_seconds' => $acceptSeconds->isEmpty() ? null : (int) round($acceptSeconds->avg()),
            'by_barangay' => $count($incidents->countBy(fn (Incident $i) => $i->barangay?->name ?? 'Unknown')->sortDesc()->take(10)),
            'by_type' => $count($incidents->countBy('emergency_type')->sortDesc()),
            'by_priority' => $count($incidents->countBy('priority')->sortDesc()),
            'by_ambulance' => $trips->groupBy('ambulance_id')->map(function (Collection $group) use ($incidents, $responseOf) {
                $times = $group->map(fn (Dispatch $d) => $incidents->firstWhere('id', $d->incident_id))
                    ->filter()->map($responseOf)->filter(fn ($v) => $v !== null);

                return [
                    'name' => $group->first()->ambulance?->name ?? 'Unknown',
                    'trips' => $group->count(),
                    'avg_response_minutes' => $times->isEmpty() ? null : round($times->avg(), 1),
                ];
            })->sortByDesc('trips')->values()->all(),
            'daily' => $this->daily($incidents, $range),
            'recent' => $incidents->take(15)->map(function (Incident $i) use ($responseOf) {
                $trip = $i->dispatches->first(fn (Dispatch $d) => in_array($d->status, [Dispatch::ACCEPTED, Dispatch::COMPLETED], true));
                $minutes = $responseOf($i);

                return [
                    'id' => $i->id,
                    'created_at' => $i->created_at->toIso8601String(),
                    'emergency_type' => $i->emergency_type,
                    'priority' => $i->priority,
                    'barangay' => $i->barangay?->name,
                    'status' => $i->status,
                    'ambulance_name' => $trip?->ambulance?->name,
                    'response_minutes' => $minutes === null ? null : round($minutes, 1),
                ];
            })->values()->all(),
        ]);
    }

    /** Calls per day for the selected range (at most the last 30 days). */
    private function daily(Collection $incidents, string $range): array
    {
        $days = $range === 'all' ? 30 : min(30, (int) $range);
        $byDate = $incidents->countBy(fn (Incident $i) => $i->created_at->toDateString());
        $rows = [];
        for ($d = $days - 1; $d >= 0; $d--) {
            $date = now()->subDays($d)->toDateString();
            $rows[] = ['date' => $date, 'count' => $byDate[$date] ?? 0];
        }

        return $rows;
    }
}
