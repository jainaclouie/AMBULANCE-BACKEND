<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Hospital;
use Illuminate\Http\JsonResponse;

class LookupController extends Controller
{
    public function barangays(): JsonResponse
    {
        return response()->json(Barangay::orderBy('name')->get()->map(fn ($b) => [
            'id' => $b->id, 'name' => $b->name, 'lat' => $b->latitude, 'lng' => $b->longitude, 'is_island' => $b->is_island,
        ]));
    }

    public function hospitals(): JsonResponse
    {
        return response()->json(Hospital::orderBy('name')->get()->map->toApi()->values());
    }
}
