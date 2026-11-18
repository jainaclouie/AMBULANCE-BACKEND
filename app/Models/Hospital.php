<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hospital extends Model
{
    protected $fillable = ['name', 'contact', 'latitude', 'longitude'];

    public function toApi(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'contact' => $this->contact, 'lat' => $this->latitude, 'lng' => $this->longitude];
    }

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float'];
    }
}
