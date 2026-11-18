<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/** In-app notification for dispatchers and admins (stored + pushed live to the bell and toast). */
class StaffAlert extends Notification
{
    /** @param string $level info | success | warning | critical */
    public function __construct(
        public string $title,
        public string $body,
        public ?int $incidentId = null,
        public string $level = 'info',
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function payload(): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'incident_id' => $this->incidentId, 'level' => $this->level];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        // 'sync' = deliver immediately, no queue worker needed for live toasts.
        return (new BroadcastMessage($this->payload()))->onConnection('sync');
    }
}
