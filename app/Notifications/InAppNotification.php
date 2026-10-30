<?php

namespace App\Notifications;

use App\Notifications\Channels\PushChannel;
use App\Support\Push\PushSender;
use Illuminate\Notifications\Notification;

/**
 * A short in-app message. Stored in the `notifications` table (the bell on the web, the Notifications screen in the app)
 * and, when the person has the mobile app installed, also sent to their phones as a push notification.
 */
class InAppNotification extends Notification
{
    public function __construct(
        public string $kind,            // e.g. membership.expiring, subscription.payment_approved
        public string $title,
        public string $body = '',
        public ?int $libraryId = null,  // null = platform-level message
        public ?string $url = null,
        public ?string $dedupe = null,  // same key => sent only once (scheduled reminders may run repeatedly)
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (PushSender::enabled() && method_exists($notifiable, 'devices') && $notifiable->devices()->exists()) {
            $channels[] = PushChannel::class;
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'library_id' => $this->libraryId,
            'url' => $this->url,
            'dedupe' => $this->dedupe,
        ];
    }

    /** What the phone shows. `kind` and `library_id` let the app open the matching screen when tapped. */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'data' => ['kind' => $this->kind, 'library_id' => $this->libraryId, 'url' => $this->url],
        ];
    }
}
