<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Tells the crew app of one ambulance to reload its state (new offer, cancelled, etc). */
class CrewStateChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $ambulanceId, public string $reason)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('ambulance.'.$this->ambulanceId)];
    }

    public function broadcastAs(): string
    {
        return 'crew.state';
    }
}
