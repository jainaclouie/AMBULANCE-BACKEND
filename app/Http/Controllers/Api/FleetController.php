<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ambulance;
use App\Models\Incident;
use App\Services\DispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FleetController extends Controller
{
    /** Everything the live map needs in one call (also used as the polling fallback). */
    public function index(): JsonResponse
    {
        $ambulances = Ambulance::with(['homeBarangay', 'crew'])->orderBy('name')->get()->map->toApi()->values();
        $incidents = Incident::with(['barangay', 'ambulance', 'activeDispatch.ambulance', 'activeDispatch.hospital'])
            ->whereIn('status', Incident::OPEN)->latest()->get()
            ->map(fn (Incident $i) => $i->toApi(true))->values();

        return response()->json(['ambulances' => $ambulances, 'incidents' => $incidents, 'server_time' => now()->toIso8601String()]);
    }

    /** Ranked list of eligible ambulances for a location (preview before the incident is saved). */
    public function recommend(Request $request, DispatchService $dispatch): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'barangay_id' => 'nullable|integer|exists:barangays,id',
        ]);

        $barangayId = isset($data['barangay_id']) ? (int) $data['barangay_id'] : null;
        $candidates = $dispatch->candidates((float) $data['lat'], (float) $data['lng'], $barangayId);

        // Explain why the home unit is not in the list (the dispatcher sees the reason, not a silent skip).
        $home = $barangayId ? Ambulance::where('home_barangay_id', $barangayId)->get() : collect();
        $eligibleIds = $candidates->map(fn ($c) => $c['ambulance']->id);
        $unavailableHome = $home->reject(fn ($a) => $eligibleIds->contains($a->id))->map(fn (Ambulance $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'status' => $a->toApi()['display_status'],
        ])->values();

        return response()->json([
            'candidates' => $dispatch->candidatePayloads($candidates),
            'unavailable_home_units' => $unavailableHome,
        ]);
    }
}
