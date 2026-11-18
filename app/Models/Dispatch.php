<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispatch extends Model
{
    public const OFFERED = 'offered';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';
    public const COMPLETED = 'completed';

    protected $fillable = [
        'incident_id', 'ambulance_id', 'status', 'stage', 'rank', 'eta_seconds', 'distance_m', 'eta_source',
        'offered_at', 'expires_at', 'responded_at', 'arrived_at', 'completed_at', 'hospital_id',
        'route_geojson', 'route_eta_seconds', 'route_distance_m', 'route_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
            'arrived_at' => 'datetime',
            'completed_at' => 'datetime',
            'route_updated_at' => 'datetime',
            'route_geojson' => 'array',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function ambulance(): BelongsTo
    {
        return $this->belongsTo(Ambulance::class);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function toApi(bool $withRoute = false): array
    {
        $data = [
            'id' => $this->id,
            'incident_id' => $this->incident_id,
            'ambulance_id' => $this->ambulance_id,
            'ambulance_name' => $this->ambulance?->name,
            'status' => $this->status,
            'stage' => $this->stage,
            'rank' => $this->rank,
            'eta_seconds' => $this->eta_seconds,
            'distance_m' => $this->distance_m,
            'eta_source' => $this->eta_source,
            'offered_at' => $this->offered_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'hospital_id' => $this->hospital_id,
            'hospital' => $this->hospital ? ['id' => $this->hospital->id, 'name' => $this->hospital->name, 'lat' => $this->hospital->latitude, 'lng' => $this->hospital->longitude] : null,
            'route_eta_seconds' => $this->route_eta_seconds,
            'route_distance_m' => $this->route_distance_m,
            'route_updated_at' => $this->route_updated_at?->toIso8601String(),
        ];
        if ($withRoute) {
            $data['route'] = $this->route_geojson;
        }

        return $data;
    }
}
