<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['ambulance_id', 'lat', 'lng', 'accuracy', 'speed', 'recorded_at'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
