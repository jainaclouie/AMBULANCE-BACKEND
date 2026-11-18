<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Incident extends Model
{
    public const AWAITING_UNIT = 'awaiting_unit';
    public const OFFERED = 'offered';
    public const EN_ROUTE = 'en_route';
    public const ON_SCENE = 'on_scene';
    public const TRANSPORTING = 'transporting';
    public const AT_HOSPITAL = 'at_hospital';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const OPEN = [self::AWAITING_UNIT, self::OFFERED, self::EN_ROUTE, self::ON_SCENE, self::TRANSPORTING, self::AT_HOSPITAL];

    protected $fillable = [
        'caller_name', 'caller_phone', 'barangay_id', 'address', 'lat', 'lng', 'emergency_type',
        'priority', 'notes', 'status', 'created_by', 'assigned_ambulance_id', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'closed_at' => 'datetime'];
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function ambulance(): BelongsTo
    {
        return $this->belongsTo(Ambulance::class, 'assigned_ambulance_id');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class);
    }

    /** The offer/trip that is currently live for this incident. */
    public function activeDispatch(): HasOne
    {
        return $this->hasOne(Dispatch::class)
            ->whereIn('status', [Dispatch::OFFERED, Dispatch::ACCEPTED])
            ->latestOfMany();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function toApi(bool $withRoute = false): array
    {
        $dispatch = $this->relationLoaded('activeDispatch') ? $this->activeDispatch : $this->activeDispatch()->first();

        return [
            'id' => $this->id,
            'caller_name' => $this->caller_name,
            'caller_phone' => $this->caller_phone,
            'barangay_id' => $this->barangay_id,
            'barangay' => $this->barangay?->name,
            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'emergency_type' => $this->emergency_type,
            'priority' => $this->priority,
            'notes' => $this->notes,
            'status' => $this->status,
            'assigned_ambulance_id' => $this->assigned_ambulance_id,
            'ambulance_name' => $this->ambulance?->name,
            'dispatch' => $dispatch?->toApi($withRoute),
            'created_at' => $this->created_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
