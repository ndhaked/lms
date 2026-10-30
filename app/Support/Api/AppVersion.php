<?php

namespace App\Support\Api;

use App\Support\BillingSettings;

/**
 * Mobile app versions, set by the Super Admin in Platform settings → Mobile app, per platform:
 *  - minimum: older apps must update (force update: the app shows a blocking screen, and the API refuses their calls with 426);
 *  - latest:  older apps are told an update is available (they can skip it);
 *  - store_url: where the update button goes (Play Store / App Store).
 * Versions are compared as 1.2.10 > 1.2.9. Empty values mean "no rule".
 */
class AppVersion
{
    public const PLATFORMS = ['android' => 'Android', 'ios' => 'iOS'];

    public static function config(string $platform): array
    {
        $all = (array) BillingSettings::get('app_versions');

        return array_replace(['latest' => '', 'minimum' => '', 'store_url' => ''], (array) ($all[$platform] ?? []));
    }

    public static function valid(?string $v): bool
    {
        return is_string($v) && preg_match('/^\d+(\.\d+){0,3}$/', trim($v)) === 1;
    }

    /** update: none | optional | required, with what the app needs to show. */
    public static function check(string $platform, ?string $version): array
    {
        $c = self::config($platform);
        $version = trim((string) $version);
        $known = self::valid($version);

        $required = $known && self::valid($c['minimum']) && version_compare($version, $c['minimum'], '<');
        $optional = ! $required && $known && self::valid($c['latest']) && version_compare($version, $c['latest'], '<');

        return [
            'platform' => $platform,
            'current_version' => $known ? $version : null,
            'latest_version' => $c['latest'] ?: null,
            'minimum_version' => $c['minimum'] ?: null,
            'update' => $required ? 'required' : ($optional ? 'optional' : 'none'),
            'force_update' => $required,
            'store_url' => $c['store_url'] ?: null,
            'message' => $required
                ? ((string) BillingSettings::get('app_update_message') ?: 'A new version of the app is required. Please update to continue.')
                : ($optional ? 'A new version of the app is available.' : null),
        ];
    }
}
