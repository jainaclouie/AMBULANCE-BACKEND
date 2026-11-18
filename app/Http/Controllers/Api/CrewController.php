<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Services\DispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrewController extends Controller
{
    public function __construct(private DispatchService $service)
    {
    }

    /** Everything the crew app needs: duty, GPS status, the pending offer and the active trip. */
    public function state(Request $request): JsonResponse
    {
        $user = $request->user()->load('ambulance.homeBarangay', 'ambulance.crew');
        $ambulance = $user->ambulance;
        abort_unless($ambulance, 422, 'This account is not linked to an ambulance.');

        $with = ['incident.barangay', 'ambulance', 'hospital'];
        $offer = Dispatch::with($with)->where('ambulance_id', $ambulance->id)->where('status', Dispatch::OFFERED)->latest('id')->first();
        $trip = Dispatch::with($with)->where('ambulance_id', $ambulance->id)->where('status', Dispatch::ACCEPTED)->latest('id')->first();

        return response()->json([
            'user' => $user->toApi(),
            'ambulance' => $ambulance->toApi(),
            'offer' => $offer ? $offer->toApi() + ['incident' => $offer->incident->toApi()] : null,
            'trip' => $trip ? $trip->toApi(true) + ['incident' => $trip->incident->toApi()] : null,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function duty(Request $request): JsonResponse
    {
        $data = $request->validate(['on_duty' => 'required|boolean']);
        $this->service->setDuty($request->user(), (bool) $data['on_duty']);

        return $this->state($request);
    }

    public function location(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'speed' => 'nullable|numeric|min:0',
        ]);
        $user = $request->user();
        abort_unless($user->ambulance && $user->on_duty, 422, 'Go on duty to share your location.');

        $this->service->updateLocation($user->ambulance, $data);

        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String()]);
    }

    public function accept(Request $request, Dispatch $dispatch): JsonResponse
    {
        $this->authorizeDispatch($request, $dispatch);
        $this->service->accept($dispatch);

        return $this->state($request);
    }

    public function decline(Request $request, Dispatch $dispatch): JsonResponse
    {
        $this->authorizeDispatch($request, $dispatch);
        $this->service->decline($dispatch);

        return $this->state($request);
    }

    public function advance(Request $request, Dispatch $dispatch): JsonResponse
    {
        $this->authorizeDispatch($request, $dispatch);
        $data = $request->validate([
            'to' => 'required|in:on_scene,transporting,at_hospital,completed',
            'hospital_id' => 'nullable|integer|exists:hospitals,id',
        ]);
        $this->service->advance($dispatch, $data['to'], $data['hospital_id'] ?? null);

        return $this->state($request);
    }

    private function authorizeDispatch(Request $request, Dispatch $dispatch): void
    {
        abort_unless((int) $dispatch->ambulance_id === (int) $request->user()->ambulance_id, 403, 'This call belongs to another ambulance.');
    }
}
