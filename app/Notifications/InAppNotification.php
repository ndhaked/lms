<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** A short in-app message. Stored in the `notifications` table; no email, queue worker or push needed. */
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
        return ['database'];
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
}
