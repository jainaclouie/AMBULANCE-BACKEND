<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Models\Hospital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminHospitalController extends Controller
{
    private const RULES = [
        'name' => 'required|string|max:150',
        'contact' => 'nullable|string|max:60',
        'lat' => 'required|numeric|between:-90,90',
        'lng' => 'required|numeric|between:-180,180',
    ];

    public function index(): JsonResponse
    {
        return response()->json(Hospital::orderBy('name')->get()->map->toApi()->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(self::RULES);
        $hospital = Hospital::create(['name' => $data['name'], 'contact' => $data['contact'] ?? null, 'latitude' => $data['lat'], 'longitude' => $data['lng']]);

        return response()->json($hospital->toApi(), 201);
    }

    public function update(Request $request, Hospital $hospital): JsonResponse
    {
        $data = $request->validate(self::RULES);
        $hospital->update(['name' => $data['name'], 'contact' => $data['contact'] ?? null, 'latitude' => $data['lat'], 'longitude' => $data['lng']]);

        return response()->json($hospital->toApi());
    }

    public function destroy(Hospital $hospital): JsonResponse
    {
        abort_if(Dispatch::where('hospital_id', $hospital->id)->where('status', Dispatch::ACCEPTED)->exists(), 422, 'An ambulance is heading to this hospital right now.');
        $hospital->delete();

        return response()->json(['message' => 'Hospital deleted.']);
    }
}
