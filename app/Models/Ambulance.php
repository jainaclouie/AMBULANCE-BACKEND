<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ambulance extends Model
{
    public const AVAILABLE = 'available';
    public const DISPATCHED = 'dispatched';      // offer sent, waiting for the crew to accept
    public const EN_ROUTE = 'en_route';
    public const ON_SCENE = 'on_scene';
    public const TRANSPORTING = 'transporting';
    public const AT_HOSPITAL = 'at_hospital';
    public const OUT_OF_SERVICE = 'out_of_service';
    public const OFFLINE = 'offline';

    public const BUSY = [self::DISPATCHED, self::EN_ROUTE, self::ON_SCENE, self::TRANSPORTING, self::AT_HOSPITAL];

    protected $fillable = ['name', 'plate_no', 'home_barangay_id', 'status', 'last_lat', 'last_lng', 'last_accuracy', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'last_lat' => 'float',
            'last_lng' => 'float',
            'last_accuracy' => 'float',
            'last_seen_at' => 'datetime',
        ];
    }

    public function homeBarangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'home_barangay_id');
    }

    public function crew(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class);
    }

    public function locationLogs(): HasMany
    {
        return $this->hasMany(LocationLog::class);
    }

    public function isGpsFresh(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gte(now()->subSeconds(config('dispatch.gps_fresh_seconds')));
    }

    public function toApi(): array
    {
        $fresh = $this->isGpsFresh();
        // A "free" unit whose GPS went silent is shown as offline: it will not be recommended.
        $display = ($this->status === self::AVAILABLE && ! $fresh) ? self::OFFLINE : $this->status;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'plate_no' => $this->plate_no,
            'home_barangay_id' => $this->home_barangay_id,
            'home_barangay' => $this->homeBarangay?->name,
            'status' => $this->status,
            'display_status' => $display,
            'gps_fresh' => $fresh,
            'lat' => $this->last_lat,
            'lng' => $this->last_lng,
            'accuracy' => $this->last_accuracy,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'crew' => $this->relationLoaded('crew')
                ? $this->crew->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'on_duty' => $u->on_duty])->values()->all()
                : [],
        ];
    }
}
