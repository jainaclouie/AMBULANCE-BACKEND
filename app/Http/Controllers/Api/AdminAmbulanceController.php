<?php

namespace App\Http\Controllers\Api;

use App\Events\AmbulanceUpdated;
use App\Http\Controllers\Controller;
use App\Models\Ambulance;
use App\Models\Dispatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminAmbulanceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Ambulance::with(['homeBarangay', 'crew'])->orderBy('name')->get()->map->toApi()->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60|unique:ambulances,name',
            'plate_no' => 'required|string|max:30|unique:ambulances,plate_no',
            'home_barangay_id' => 'nullable|integer|exists:barangays,id',
        ]);
        $ambulance = Ambulance::create($data + ['status' => Ambulance::OFFLINE]);
        event(new AmbulanceUpdated($ambulance));

        return response()->json($ambulance->load(['homeBarangay', 'crew'])->toApi(), 201);
    }

    public function show(Ambulance $ambulance): JsonResponse
    {
        return response()->json($ambulance->load(['homeBarangay', 'crew'])->toApi());
    }

    public function update(Request $request, Ambulance $ambulance): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60', Rule::unique('ambulances', 'name')->ignore($ambulance->id)],
            'plate_no' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('ambulances', 'plate_no')->ignore($ambulance->id)],
            'home_barangay_id' => 'sometimes|nullable|integer|exists:barangays,id',
            'out_of_service' => 'sometimes|boolean',
        ]);

        if (array_key_exists('out_of_service', $data)) {
            abort_if(in_array($ambulance->status, Ambulance::BUSY, true), 422, 'This ambulance is on a call. Wait until the incident is completed.');
            $ambulance->status = $data['out_of_service'] ? Ambulance::OUT_OF_SERVICE : Ambulance::OFFLINE;
            unset($data['out_of_service']);
        }
        $ambulance->fill($data)->save();
        event(new AmbulanceUpdated($ambulance));

        return response()->json($ambulance->load(['homeBarangay', 'crew'])->toApi());
    }

    public function destroy(Ambulance $ambulance): JsonResponse
    {
        abort_if(Dispatch::where('ambulance_id', $ambulance->id)->whereIn('status', [Dispatch::OFFERED, Dispatch::ACCEPTED])->exists(), 422, 'This ambulance has an active call.');
        $ambulance->delete();

        return response()->json(['message' => 'Ambulance deleted.']);
    }
}
