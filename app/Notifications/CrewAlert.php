<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Notification for an ambulance crew. Delivered three ways:
 *  1. database  - history
 *  2. broadcast - live inside the open app (sound + vibration)
 *  3. web push  - phone notification even when the app is closed (needs a saved push subscription)
 */
class CrewAlert extends Notification
{
    public function __construct(
        public string $title,
        public string $body,
        public bool $urgent = false,
        public ?int $incidentId = null,
        public ?int $dispatchId = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];
        if (method_exists($notifiable, 'pushSubscriptions') && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function payload(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'urgent' => $this->urgent,
            'incident_id' => $this->incidentId,
            'dispatch_id' => $this->dispatchId,
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->payload()))->onConnection('sync');
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        $message = (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/icons/icon-192.png')
            ->badge('/icons/icon-192.png')
            ->tag($this->dispatchId ? 'offer-'.$this->dispatchId : 'crew-alert')
            ->data(['url' => '/crew', 'dispatch_id' => $this->dispatchId, 'incident_id' => $this->incidentId]);

        if ($this->urgent) {
            $message->requireInteraction()->renotify()->vibrate([300, 150, 300, 150, 600]);
            $message->options(['TTL' => max(30, (int) config('dispatch.offer_ttl_seconds')), 'urgency' => 'high']);
        }

        return $message;
    }
}
