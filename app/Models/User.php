<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_DISPATCHER = 'dispatcher';
    public const ROLE_CREW = 'crew';

    protected $fillable = ['name', 'email', 'password', 'role', 'phone', 'ambulance_id', 'on_duty'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'on_duty' => 'boolean',
        ];
    }

    public function ambulance(): BelongsTo
    {
        return $this->belongsTo(Ambulance::class);
    }

    public function isStaff(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_DISPATCHER], true);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'phone' => $this->phone,
            'ambulance_id' => $this->ambulance_id,
            'ambulance_name' => $this->ambulance?->name,
            'on_duty' => $this->on_duty,
        ];
    }
}
