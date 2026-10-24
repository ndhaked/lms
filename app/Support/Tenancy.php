<?php

namespace App\Support;

use App\Models\Library;

/**
 * Single source of truth for the active library.
 * Set only by middleware after membership has been verified (or explicitly in jobs/seeders/tests).
 *
 * While a library is active, PHP's default time zone is that library's configured zone, so every
 * "today", "now" and stored timestamp for its data is consistent and shown in its local time.
 */
class Tenancy
{
    protected static ?int $id = null;

    protected static ?Library $model = null;

    protected static ?string $platformTz = null;

    public static function set(?Library $library): void
    {
        static::$id = $library?->getKey();
        static::$model = $library;
        setPermissionsTeamId(static::$id);
        static::applyTimezone($library?->timezone);
        Entitlements::flush();   // the subscription belongs to the library, so never carry it across
    }

    public static function currentId(): ?int
    {
        return static::$id;
    }

    /** The active library model (one query per request, then cached). */
    public static function library(): ?Library
    {
        if (static::$id === null) {
            return null;
        }
        if (static::$model?->getKey() !== static::$id) {
            static::$model = Library::find(static::$id);
        }

        return static::$model;
    }

    public static function slug(): string
    {
        return static::library()?->slug ?? '';
    }

    public static function run(Library $library, \Closure $callback): mixed
    {
        $previous = static::$model ?? (static::$id ? Library::find(static::$id) : null);
        static::set($library);
        try {
            return $callback();
        } finally {
            static::set($previous);
        }
    }

    public static function flush(): void
    {
        static::set(null);
    }

    protected static function applyTimezone(?string $tz): void
    {
        static::$platformTz ??= date_default_timezone_get();

        $tz = $tz && in_array($tz, timezone_identifiers_list(), true) ? $tz : static::$platformTz;

        date_default_timezone_set($tz);
        config(['app.timezone' => $tz]);
    }
}
