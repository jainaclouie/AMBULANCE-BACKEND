<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Road distance / travel time and turn-by-turn route geometry.
 *
 * Uses an OSRM server (OpenStreetMap data). If OSRM cannot be reached, it falls back to a
 * straight-line estimate so dispatching never stops - the response says source = "estimate".
 */
class RoutingService
{
    /**
     * @param  array<int, array{0: float, 1: float}>  $origins  list of [lat, lng]
     * @return array<int, array{eta_seconds: int, distance_m: int, source: string}> same order as $origins
     */
    public function etas(array $origins, float $lat, float $lng): array
    {
        if (empty($origins)) {
            return [];
        }

        $results = [];
        try {
            $coords = collect($origins)->map(fn ($o) => $o[1].','.$o[0])->push($lng.','.$lat)->implode(';');
            $response = Http::timeout(config('dispatch.osrm_timeout'))->get(
                config('dispatch.osrm_url').'/table/v1/driving/'.$coords,
                [
                    'sources' => implode(';', range(0, count($origins) - 1)),
                    'destinations' => count($origins),
                    'annotations' => 'duration,distance',
                ]
            );

            if ($response->ok() && $response->json('code') === 'Ok') {
                $durations = $response->json('durations');
                $distances = $response->json('distances');
                foreach ($origins as $i => $o) {
                    $d = $durations[$i][0] ?? null;
                    if ($d === null) {
                        $results[$i] = $this->estimate($o[0], $o[1], $lat, $lng);
                    } else {
                        $results[$i] = [
                            'eta_seconds' => (int) round($d),
                            'distance_m' => (int) round($distances[$i][0] ?? 0),
                            'source' => 'osrm',
                        ];
                    }
                }

                return $results;
            }
        } catch (\Throwable $e) {
            Log::warning('OSRM table request failed: '.$e->getMessage());
        }

        foreach ($origins as $i => $o) {
            $results[$i] = $this->estimate($o[0], $o[1], $lat, $lng);
        }

        return $results;
    }

    /**
     * @return array{geometry: array, duration: int, distance: int, source: string}
     */
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        try {
            $response = Http::timeout(config('dispatch.osrm_timeout'))->get(
                config('dispatch.osrm_url')."/route/v1/driving/{$fromLng},{$fromLat};{$toLng},{$toLat}",
                ['overview' => 'full', 'geometries' => 'geojson', 'steps' => 'false']
            );
            if ($response->ok() && $response->json('code') === 'Ok' && $response->json('routes.0.geometry')) {
                return [
                    'geometry' => $response->json('routes.0.geometry'),
                    'duration' => (int) round($response->json('routes.0.duration')),
                    'distance' => (int) round($response->json('routes.0.distance')),
                    'source' => 'osrm',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('OSRM route request failed: '.$e->getMessage());
        }

        $est = $this->estimate($fromLat, $fromLng, $toLat, $toLng);

        return [
            'geometry' => ['type' => 'LineString', 'coordinates' => [[$fromLng, $fromLat], [$toLng, $toLat]]],
            'duration' => $est['eta_seconds'],
            'distance' => $est['distance_m'],
            'source' => 'estimate',
        ];
    }

    /** Straight-line estimate used only when routing is unavailable. */
    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $road = self::haversine($fromLat, $fromLng, $toLat, $toLng) * config('dispatch.fallback_road_factor');
        $speed = max(1, config('dispatch.fallback_speed_kmh')) * 1000 / 3600; // m/s

        return ['eta_seconds' => (int) round($road / $speed), 'distance_m' => (int) round($road), 'source' => 'estimate'];
    }

    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
