<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** An incident was created or its status changed. Route geometry is NOT sent (clients fetch it by REST). */
class IncidentUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public array $incident;

    public function __construct(Incident $incident)
    {
        $this->incident = $incident->loadMissing(['barangay', 'ambulance'])->toApi(false);
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('fleet')];
    }

    public function broadcastAs(): string
    {
        return 'incident.updated';
    }
}
