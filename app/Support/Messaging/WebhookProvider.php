<?php

namespace App\Support\Messaging;

use App\Support\Messaging as Msg;
use Illuminate\Support\Facades\Http;

/** Posts every message as JSON to a link you give it: a way to plug in any gateway without a dedicated adapter. */
class WebhookProvider implements MessageProvider
{
    public function key(): string
    {
        return 'webhook';
    }

    public function label(): string
    {
        return 'Custom webhook';
    }

    public function channels(): array
    {
        return ['sms', 'whatsapp'];
    }

    public function fields(): array
    {
        return [
            ['key' => 'url', 'label' => 'Provider link (https)', 'type' => 'url', 'required' => true],
            ['key' => 'token', 'label' => 'Access token (optional)', 'type' => 'secret', 'hint' => 'Sent as a Bearer token.'],
        ];
    }

    public function templateFields(): array
    {
        return [];
    }

    public function usesEventTemplates(): bool
    {
        return false;
    }

    public function send(string $channel, string $to, string $message, array $context): array
    {
        $cfg = ProviderRegistry::config('webhook');
        $url = (string) ($cfg['url'] ?? '');
        if (! str_starts_with($url, 'https://')) {
            return [false, 'The webhook link is not set up'];
        }

        try {
            $request = Http::timeout(8)->acceptJson();
            if (filled($cfg['token'] ?? null)) {
                $request = $request->withToken($cfg['token']);
            }
            $response = $request->post($url, ['channel' => $channel, 'to' => Msg::normalizeMobile($to), 'message' => $message, 'event' => $context['event'] ?? null, 'library' => $context['library'] ?? null]);

            $raw = $response->status().' '.mb_substr($response->body(), 0, 800);

            return $response->successful() ? [true, null, $raw] : [false, 'Provider replied '.$response->status(), $raw];
        } catch (\Throwable $e) {
            return [false, 'Could not reach the provider', $e->getMessage()];
        }
    }
}
