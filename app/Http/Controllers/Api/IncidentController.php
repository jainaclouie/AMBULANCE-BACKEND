<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ambulance;
use App\Models\Incident;
use App\Services\DispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    private const WITH = ['barangay', 'ambulance', 'activeDispatch.ambulance', 'activeDispatch.hospital'];

    public function index(): JsonResponse
    {
        return response()->json(
            Incident::with(self::WITH)->whereIn('status', Incident::OPEN)->latest()->get()->map(fn ($i) => $i->toApi())->values()
        );
    }

    public function store(Request $request, DispatchService $dispatch): JsonResponse
    {
        $data = $request->validate([
            'caller_name' => 'required|string|max:120',
            'caller_phone' => 'required|string|max:40',
            'barangay_id' => 'required|integer|exists:barangays,id',
            'address' => 'nullable|string|max:255',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'emergency_type' => 'required|in:cardiac,accident,childbirth,breathing,other',
            'priority' => 'required|in:low,medium,high,critical',
            'notes' => 'nullable|string|max:1000',
            'ambulance_id' => 'nullable|integer|exists:ambulances,id',
        ]);

        $incident = $dispatch->createIncident($data, $request->user(), $data['ambulance_id'] ?? null);
        $incident->load(self::WITH);

        return response()->json($incident->toApi(true), 201);
    }

    public function show(Incident $incident): JsonResponse
    {
        return response()->json($incident->load(self::WITH)->toApi(true));
    }

    public function assign(Request $request, Incident $incident, DispatchService $dispatch): JsonResponse
    {
        $data = $request->validate(['ambulance_id' => 'required|integer|exists:ambulances,id']);
        abort_unless($incident->isOpen(), 422, 'This incident is already closed.');
        abort_if(in_array($incident->status, [Incident::ON_SCENE, Incident::TRANSPORTING, Incident::AT_HOSPITAL], true), 422, 'The patient is already being attended. Reassigning now is not allowed.');

        $offer = $dispatch->reassign($incident, Ambulance::findOrFail($data['ambulance_id']));
        abort_if(! $offer, 409, 'That ambulance is no longer available. Pick another unit.');

        return response()->json($incident->fresh(self::WITH)->toApi(true));
    }

    public function cancel(Incident $incident, DispatchService $dispatch): JsonResponse
    {
        abort_unless($incident->isOpen(), 422, 'This incident is already closed.');
        $dispatch->cancelIncident($incident->load('barangay'));

        return response()->json($incident->fresh(self::WITH)->toApi());
    }
}
