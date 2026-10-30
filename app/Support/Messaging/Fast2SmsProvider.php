<?php

namespace App\Support\Messaging;

use Illuminate\Support\Facades\Http;

/**
 * Fast2SMS (fast2sms.com): SMS only.
 *
 * Route "Quick SMS" (q) sends the filled-in text as it is, which is the simplest way to test (free test credit applies).
 * Route "DLT" sends a DLT-approved template by its id with variable values, which is what production SMS in India needs:
 * enter the sender id and, for each automatic message, the template id and the placeholders that fill its variables in order.
 */
class Fast2SmsProvider implements MessageProvider
{
    protected const URL = 'https://www.fast2sms.com/dev/bulkV2';

    public function key(): string
    {
        return 'fast2sms';
    }

    public function label(): string
    {
        return 'Fast2SMS';
    }

    public function channels(): array
    {
        return ['sms'];
    }

    public function fields(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'secret', 'required' => true, 'hint' => 'Fast2SMS dashboard → Dev API → API key (the "authorization" key).'],
            ['key' => 'route', 'label' => 'Route', 'type' => 'select', 'options' => ['q' => 'Quick SMS: send the text as written (testing)', 'dlt' => 'DLT: send an approved template (production)'], 'required' => true],
            ['key' => 'sender_id', 'label' => 'DLT sender id', 'type' => 'text', 'hint' => 'Your 6-letter approved sender id. DLT route only.'],
        ];
    }

    public function templateFields(): array
    {
        return [
            ['key' => 'sms_template_id', 'label' => 'DLT template id', 'channel' => 'sms', 'placeholder' => 'DLT route only'],
            ['key' => 'sms_vars', 'label' => 'Variables in order', 'channel' => 'sms', 'placeholder' => 'name,date,library'],
        ];
    }

    public function usesEventTemplates(): bool
    {
        return true;
    }

    public function send(string $channel, string $to, string $message, array $context): array
    {
        $cfg = ProviderRegistry::config('fast2sms');
        if (blank($cfg['api_key'] ?? null)) {
            return [false, 'Fast2SMS API key is not set'];
        }

        $number = substr(preg_replace('/\D+/', '', $to), -10);      // Fast2SMS takes the 10-digit Indian number
        $route = ($cfg['route'] ?? 'q') === 'dlt' ? 'dlt' : 'q';

        if ($route === 'dlt') {
            $tpl = (array) ($cfg['templates'][(string) ($context['event'] ?? '')] ?? []);
            if (blank($tpl['sms_template_id'] ?? null)) {
                return [false, 'No DLT template id is set for this message'];
            }
            if (blank($cfg['sender_id'] ?? null)) {
                return [false, 'The DLT sender id is not set'];
            }
            $vars = (array) ($context['vars'] ?? []);
            $values = collect(explode(',', (string) ($tpl['sms_vars'] ?? '')))->map(fn ($v) => trim($v, " {}\t"))->filter()->map(fn ($v) => str_replace('|', ' ', (string) ($vars[$v] ?? '')))->implode('|');
            $body = ['route' => 'dlt', 'sender_id' => $cfg['sender_id'], 'message' => $tpl['sms_template_id'], 'variables_values' => $values, 'flash' => 0, 'numbers' => $number];
        } else {
            $body = ['route' => 'q', 'message' => $message, 'language' => 'english', 'flash' => 0, 'numbers' => $number];
        }

        try {
            $response = Http::timeout(10)->acceptJson()->withHeaders(['authorization' => $cfg['api_key']])->post(self::URL, $body);
        } catch (\Throwable $e) {
            return [false, 'Could not reach Fast2SMS', $e->getMessage()];
        }

        $raw = $response->status().' '.mb_substr($response->body(), 0, 800);
        if ($response->successful() && $response->json('return') === true) {
            return [true, null, $raw];
        }

        $why = $response->json('message');
        $why = is_array($why) ? implode(' ', $why) : (string) ($why ?: 'rejected');

        return [false, 'Fast2SMS: '.mb_substr($why, 0, 150), $raw];
    }
}
