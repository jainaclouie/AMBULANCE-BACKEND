<?php

namespace App\Events;

use App\Models\Ambulance;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Live fleet map: an ambulance moved or changed status. */
class AmbulanceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public array $ambulance;

    public function __construct(Ambulance $ambulance)
    {
        $this->ambulance = $ambulance->loadMissing(['homeBarangay', 'crew'])->toApi();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('fleet')];
    }

    public function broadcastAs(): string
    {
        return 'ambulance.updated';
    }
}
