<?php

namespace App\Support\Messaging;

use App\Support\BillingSettings;
use Illuminate\Support\Facades\Crypt;

/** The providers the platform knows about, which one each channel uses, and their saved settings (secrets encrypted). */
class ProviderRegistry
{
    /** Add new providers here. */
    public const CLASSES = [Msg91Provider::class, Fast2SmsProvider::class, MetaWhatsappProvider::class, WebhookProvider::class, TestProvider::class];

    /** @return array<string, MessageProvider> key => provider */
    public static function all(): array
    {
        $out = [];
        foreach (self::CLASSES as $class) {
            $p = new $class;
            $out[$p->key()] = $p;
        }

        return $out;
    }

    public static function find(string $key): ?MessageProvider
    {
        return self::all()[$key] ?? null;
    }

    /** Providers that can carry a channel. @return array<string, MessageProvider> */
    public static function forChannel(string $channel): array
    {
        return array_filter(self::all(), fn (MessageProvider $p) => in_array($channel, $p->channels(), true));
    }

    /** The provider an admin switched a channel to, or null when it is off. */
    public static function active(string $channel): ?MessageProvider
    {
        $key = (string) (BillingSettings::get('messaging_active')[$channel] ?? 'none');
        $p = self::find($key);

        return $p && in_array($channel, $p->channels(), true) ? $p : null;
    }

    /** @return array<string, mixed> saved settings of a provider, secrets decrypted */
    public static function config(string $key): array
    {
        $p = self::find($key);
        $saved = (array) (BillingSettings::get('messaging_providers')[$key] ?? []);
        if (! $p) {
            return [];
        }

        foreach ($p->fields() as $f) {
            if ($f['type'] === 'secret' && filled($saved[$f['key']] ?? null)) {
                try {
                    $saved[$f['key']] = Crypt::decryptString($saved[$f['key']]);
                } catch (\Throwable) {
                    $saved[$f['key']] = '';
                }
            }
        }

        return $saved;
    }

    /**
     * Save a provider's settings. A blank secret keeps the one already saved.
     *
     * @param  array<string, mixed>  $values  field values, plus "templates" for providers that map templates per event
     */
    public static function save(string $key, array $values): void
    {
        $p = self::find($key) ?? throw new \InvalidArgumentException("Unknown provider {$key}");
        $all = (array) BillingSettings::get('messaging_providers');
        $old = (array) ($all[$key] ?? []);
        $new = [];

        foreach ($p->fields() as $f) {
            $v = trim((string) ($values[$f['key']] ?? ''));
            if ($f['type'] === 'secret') {
                $new[$f['key']] = $v === '' ? ($old[$f['key']] ?? '') : Crypt::encryptString($v);
            } else {
                $new[$f['key']] = $v;
            }
        }
        if ($p->usesEventTemplates()) {
            $new['templates'] = array_map(fn ($t) => array_map(fn ($x) => trim((string) $x), (array) $t), (array) ($values['templates'] ?? []));
        }

        $all[$key] = $new;
        BillingSettings::set(['messaging_providers' => $all]);
    }

    /** Is a secret already saved? (the settings page shows "saved" instead of the value) */
    public static function hasSecret(string $key, string $field): bool
    {
        return filled((BillingSettings::get('messaging_providers')[$key][$field] ?? null));
    }
}
