<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Platform-wide billing and payment settings (table subscription_settings). */
class BillingSettings
{
    public const DEFAULTS = [
        // payments
        'payment_methods' => ['bank_transfer', 'upi'],
        'payment_instructions' => '',
        'proof_required' => true,
        'require_exact_amount' => true,
        // public website
        'landing_enabled' => true,           // false: "/" goes straight to the login page
        'offer_free_days' => 60,             // the "first N days free" offer shown on the website; 0 hides it
        // add-on modules: standard price per cycle; blank quarterly/annual = monthly x 3 / x 12
        'module_prices' => [
            'books' => ['monthly' => '299', 'quarterly' => '799', 'annual' => '2999'],
            'ebooks' => ['monthly' => '199', 'quarterly' => '549', 'annual' => '1999'],
        ],
        // subscriptions
        'default_trial_days' => 14,
        'default_grace_days' => 7,
        'renewal_reminder_days' => 7,
        'expiry_policy' => 'billing_only',   // after grace: only the billing page stays usable; data is never deleted
        // general
        'app_name' => '',
        'support_email' => 'nirbhaydhaked@gmail.com',
        'support_phone' => '+91 82099 90511',
        'default_timezone' => 'Asia/Kolkata',
        'default_currency' => 'INR',
        'upload_limit_kb' => 4096,
        'maintenance_notice' => '',
        'retention_policy' => 'Library data is retained indefinitely, including after suspension or cancellation.',
    ];

    protected static ?array $cache = null;

    public static function all(): array
    {
        return self::$cache ??= array_replace(
            self::DEFAULTS,
            DB::table('subscription_settings')->pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all(),
        );
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function set(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            DB::table('subscription_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
            );
        }

        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
