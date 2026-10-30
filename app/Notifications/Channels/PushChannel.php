<?php

namespace App\Notifications\Channels;

use App\Support\Push\PushSender;
use Illuminate\Notifications\Notification;

/**
 * Delivers a notification to the person's phones. Sent after the response is returned
 * (Laravel's defer), so a slow push service never delays the request that caused it.
 */
class PushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush') || ! method_exists($notifiable, 'devices')) {
            return;
        }

        $push = $notification->toPush($notifiable);
        $devices = $notifiable->devices()->get();
        if ($devices->isEmpty()) {
            return;
        }

        $deliver = fn () => PushSender::send($devices, $push['title'], $push['body'], ['notification_id' => $notification->id] + $push['data']);

        app()->runningInConsole() && ! app()->runningUnitTests() ? $deliver() : \Illuminate\Support\defer($deliver);
    }
}
