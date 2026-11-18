<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Barangay extends Model
{
    protected $fillable = ['name', 'latitude', 'longitude', 'is_island'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'is_island' => 'boolean'];
    }
}
