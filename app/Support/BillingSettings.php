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
        // SMS / WhatsApp credits: price per credit, struck-through list price, minimum purchase and the pack sizes offered
        'credit_rates' => [
            'sms' => ['rate' => '0.21', 'mrp' => '0.25', 'min' => 1000, 'packs' => [1000, 2000, 5000, 10000]],
            'whatsapp' => ['rate' => '0.10', 'mrp' => '0.15', 'min' => 1000, 'packs' => [1000, 2000, 5000, 10000]],
        ],
        // referral program: a library earns coins when a library it referred pays; coins are a discount on subscriptions
        'referral_enabled' => true,
        'referral_reward_coins' => 100,      // coins the referrer earns when the referred library's first payment is approved
        'coin_value' => 1,                   // rupees one coin is worth
        'referral_redeem_percent' => 20,     // the most of a subscription price that coins may pay for
        // colour themes libraries may pick from (empty = all) and the one used until a library picks its own
        'themes_enabled' => [],
        'default_theme' => 'default',
        // Razorpay online payments (the secrets are stored encrypted, see App\Support\Razorpay)
        'razorpay_enabled' => false,
        'razorpay_key_id' => '',
        'razorpay_key_secret' => '',
        'razorpay_webhook_secret' => '',
        // how SMS / WhatsApp messages leave: the provider each channel uses (none = nothing is sent) and each provider's settings
        'messaging_active' => ['sms' => 'none', 'whatsapp' => 'none'],   // channel => provider key (see App\Support\Messaging\ProviderRegistry)
        'messaging_providers' => [],                                       // provider key => its saved settings (secrets encrypted)
        // message templates: system SMS list and the WhatsApp set new libraries start with (empty = built-in, see MessageTemplates)
        'sms_templates' => [],
        'whatsapp_default_templates' => [],
        // Need Help? page: edited questions (empty = the built-in set in HelpContent)
        'help_faqs' => [],
        // Need Help? page: question key => https video link (see App\Support\HelpContent), plus one full walkthrough
        'help_videos' => [],
        'help_full_video' => '',
        // mobile app push notifications (secrets stored encrypted, see App\Support\Push\PushSender)
        'push_enabled' => true,
        'push_expo_access_token' => '',
        'push_fcm_project_id' => '',
        'push_fcm_credentials' => '',          // Firebase service-account JSON key
        // Swagger page for the mobile API (/api/docs): auto = on everywhere except the live (production) site
        'api_docs' => 'auto',                  // auto | on | off
        // app versions per platform: below 'minimum' the app must update (force update), below 'latest' it may (see App\Support\Api\AppVersion)
        'app_versions' => ['android' => ['latest' => '', 'minimum' => '', 'store_url' => ''], 'ios' => ['latest' => '', 'minimum' => '', 'store_url' => '']],
        'app_update_message' => '',
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
