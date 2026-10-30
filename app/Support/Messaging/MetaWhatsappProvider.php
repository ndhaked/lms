<?php

namespace App\Support\Messaging;

use App\Support\Messaging as Msg;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp through Meta's own Cloud API (developers.facebook.com): WhatsApp only.
 *
 * Testing is free: Meta gives a test phone number, and you add up to five recipient numbers to its allowed list.
 * Messages that start a conversation must use an approved template (the test number includes "hello_world"). So for each automatic
 * message enter the template name and, in order, the placeholders that fill its {{1}} {{2}} ... slots. If a message has no template
 * name the filled-in text is sent as a plain message, which Meta delivers only inside the 24 hours after the student last wrote to you.
 */
class MetaWhatsappProvider implements MessageProvider
{
    public function key(): string
    {
        return 'meta_whatsapp';
    }

    public function label(): string
    {
        return 'Meta WhatsApp Cloud API';
    }

    public function channels(): array
    {
        return ['whatsapp'];
    }

    public function fields(): array
    {
        return [
            ['key' => 'access_token', 'label' => 'Access token', 'type' => 'secret', 'required' => true, 'hint' => 'A permanent system-user token (the temporary one lasts 24 hours).'],
            ['key' => 'phone_number_id', 'label' => 'Phone number ID', 'type' => 'text', 'required' => true, 'hint' => 'Meta → WhatsApp → API setup. This is the id, not the phone number itself.'],
            ['key' => 'language', 'label' => 'Template language', 'type' => 'text', 'hint' => 'For example en_US. Defaults to en_US.'],
            ['key' => 'api_version', 'label' => 'Graph API version', 'type' => 'text', 'hint' => 'Defaults to v20.0.'],
        ];
    }

    public function templateFields(): array
    {
        return [
            ['key' => 'wa_template', 'label' => 'Template name', 'channel' => 'whatsapp', 'placeholder' => 'hello_world'],
            ['key' => 'wa_vars', 'label' => 'Variables in order', 'channel' => 'whatsapp', 'placeholder' => 'name,date,library'],
        ];
    }

    public function usesEventTemplates(): bool
    {
        return true;
    }

    public function send(string $channel, string $to, string $message, array $context): array
    {
        $cfg = ProviderRegistry::config('meta_whatsapp');
        $token = (string) ($cfg['access_token'] ?? '');
        $phoneId = preg_replace('/\D+/', '', (string) ($cfg['phone_number_id'] ?? ''));
        if ($token === '' || $phoneId === '') {
            return [false, $token === '' ? 'The Meta access token is not set' : 'The Meta phone number ID is not set'];
        }

        $tpl = (array) ($cfg['templates'][(string) ($context['event'] ?? '')] ?? []);
        $name = trim((string) ($tpl['wa_template'] ?? ''));
        $body = ['messaging_product' => 'whatsapp', 'to' => Msg::normalizeMobile($to)];

        if ($name !== '') {
            $vars = (array) ($context['vars'] ?? []);
            $params = collect(explode(',', (string) ($tpl['wa_vars'] ?? '')))->map(fn ($v) => trim($v, " {}\t"))->filter()
                ->map(fn ($v) => ['type' => 'text', 'text' => (string) (($vars[$v] ?? '') !== '' ? $vars[$v] : '-')])->values()->all();
            $body += ['type' => 'template', 'template' => ['name' => $name, 'language' => ['code' => ($cfg['language'] ?? '') ?: 'en_US']]];
            if ($params) {
                $body['template']['components'] = [['type' => 'body', 'parameters' => $params]];
            }
        } else {
            $body += ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $message]];
        }

        $version = preg_match('/^v\d+\.\d+$/', (string) ($cfg['api_version'] ?? '')) ? $cfg['api_version'] : 'v20.0';

        try {
            $response = Http::timeout(10)->acceptJson()->withToken($token)->post("https://graph.facebook.com/{$version}/{$phoneId}/messages", $body);
        } catch (\Throwable $e) {
            return [false, 'Could not reach Meta', $e->getMessage()];
        }

        $raw = $response->status().' '.mb_substr($response->body(), 0, 800);
        if ($response->successful() && filled($response->json('messages.0.id'))) {
            return [true, null, $raw];
        }

        $err = $response->json('error.message') ?: 'rejected';
        $code = $response->json('error.code');

        return [false, 'Meta: '.mb_substr((string) $err, 0, 150).($code ? " (code {$code})" : ''), $raw];
    }
}
