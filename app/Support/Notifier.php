<?php

namespace App\Support;

use App\Models\Library;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

class Notifier
{
    /** True if a message with this dedupe key was already sent. */
    public static function alreadySent(string $dedupe): bool
    {
        return DatabaseNotification::where('data->dedupe', $dedupe)->exists();
    }

    /** Notify everyone in the library who holds $permission (checked inside that library's role scope). */
    public static function library(Library $library, string $permission, string $kind, string $title, string $body = '', ?string $url = null, ?string $dedupe = null): int
    {
        if ($dedupe && self::alreadySent($dedupe)) {
            return 0;
        }

        $recipients = Tenancy::run($library, fn () => $library->users()->wherePivot('is_active', true)->get()
            ->filter(fn (User $u) => $u->can($permission))->values());

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new InAppNotification($kind, $title, $body, $library->id, $url, $dedupe));
        }

        return $recipients->count();
    }

    /** Notify platform administrators who hold a platform permission (e.g. payments.approve). */
    public static function platform(string $permission, string $kind, string $title, string $body = '', ?string $url = null, ?string $dedupe = null): int
    {
        if ($dedupe && self::alreadySent($dedupe)) {
            return 0;
        }

        $admins = User::where('is_super_admin', true)->where('is_active', true)->get()->filter(fn (User $u) => $u->hasPlatformPermission($permission))->values();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new InAppNotification($kind, $title, $body, null, $url, $dedupe));
        }

        return $admins->count();
    }

    /** Notify one user within a library. */
    public static function user(User $user, ?int $libraryId, string $kind, string $title, string $body = '', ?string $url = null, ?string $dedupe = null): bool
    {
        if ($dedupe && self::alreadySent($dedupe)) {
            return false;
        }

        $user->notify(new InAppNotification($kind, $title, $body, $libraryId, $url, $dedupe));

        return true;
    }
}
