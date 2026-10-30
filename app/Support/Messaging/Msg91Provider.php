<?php

namespace App\Support\Messaging;

use App\Support\Messaging as Msg;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * MSG91 (msg91.com): SMS through the Flow API and WhatsApp through the outbound-message API.
 *
 * SMS in India must use a DLT-approved template, and MSG91 sends it by template id. So for each automatic message the admin
 * enters the MSG91 template id (SMS) and the approved template name (WhatsApp). The placeholder values ({name}, {date}, {amount},
 * {library}, {plan}, {due}) are sent as variables with those same names, so name the variables that way in the MSG91 template.
 * For WhatsApp, "variables" lists which placeholders fill the template's {{1}}, {{2}} ... in order.
 */
class Msg91Provider implements MessageProvider
{
    protected const SMS_URL = 'https://control.msg91.com/api/v5/flow/';
    protected const WHATSAPP_URL = 'https://control.msg91.com/api/v5/whatsapp/whatsapp-outbound-message/bulk/';

    public function key(): string
    {
        return 'msg91';
    }

    public function label(): string
    {
        return 'MSG91';
    }

    public function channels(): array
    {
        return ['sms', 'whatsapp'];
    }

    public function fields(): array
    {
        return [
            ['key' => 'authkey', 'label' => 'Auth key', 'type' => 'secret', 'required' => true, 'hint' => 'MSG91 dashboard → API → Auth key.'],
            ['key' => 'whatsapp_number', 'label' => 'WhatsApp number (integrated)', 'type' => 'text', 'hint' => 'The business number linked in MSG91, with country code. Needed for WhatsApp only.'],
            ['key' => 'whatsapp_namespace', 'label' => 'WhatsApp template namespace', 'type' => 'text', 'hint' => 'Shown with your approved WhatsApp templates in MSG91.'],
            ['key' => 'whatsapp_language', 'label' => 'WhatsApp template language', 'type' => 'text', 'hint' => 'For example en or en_US. Defaults to en.'],
        ];
    }

    public function templateFields(): array
    {
        return [
            ['key' => 'sms_template_id', 'label' => 'SMS template id', 'channel' => 'sms', 'placeholder' => 'e.g. 65f1a2b3…'],
            ['key' => 'wa_template', 'label' => 'WhatsApp template name', 'channel' => 'whatsapp', 'placeholder' => 'approved template name'],
            ['key' => 'wa_vars', 'label' => 'WhatsApp variables', 'channel' => 'whatsapp', 'placeholder' => 'name,date,library'],
        ];
    }

    public function usesEventTemplates(): bool
    {
        return true;
    }

    public function send(string $channel, string $to, string $message, array $context): array
    {
        $cfg = ProviderRegistry::config('msg91');
        if (blank($cfg['authkey'] ?? null)) {
            return [false, 'MSG91 auth key is not set'];
        }

        $event = (string) ($context['event'] ?? '');
        $tpl = (array) ($cfg['templates'][$event] ?? []);
        $vars = array_map('strval', (array) ($context['vars'] ?? []));
        $to = Msg::normalizeMobile($to);

        try {
            return $channel === 'sms' ? $this->sms($cfg, $tpl, $to, $vars) : $this->whatsapp($cfg, $tpl, $to, $vars);
        } catch (\Throwable $e) {
            return [false, 'Could not reach MSG91', $e->getMessage()];
        }
    }

    protected function sms(array $cfg, array $tpl, string $to, array $vars): array
    {
        $templateId = (string) ($tpl['sms_template_id'] ?? '');
        if ($templateId === '') {
            return [false, 'No MSG91 SMS template id is set for this message'];
        }

        $response = Http::timeout(10)->acceptJson()->withHeaders(['authkey' => $cfg['authkey']])->post(self::SMS_URL, [
            'template_id' => $templateId,
            'short_url' => '0',
            'realTimeResponse' => '1',
            'recipients' => [array_merge(['mobiles' => $to], $vars)],
        ]);

        return $this->outcome($response);
    }

    protected function whatsapp(array $cfg, array $tpl, string $to, array $vars): array
    {
        $name = (string) ($tpl['wa_template'] ?? '');
        $number = preg_replace('/\D+/', '', (string) ($cfg['whatsapp_number'] ?? ''));
        if ($name === '' || $number === '') {
            return [false, $number === '' ? 'No MSG91 WhatsApp number is set' : 'No MSG91 WhatsApp template is set for this message'];
        }

        $components = [];
        foreach (array_values(array_filter(array_map('trim', explode(',', (string) ($tpl['wa_vars'] ?? ''))))) as $i => $var) {
            $components['body_'.($i + 1)] = ['type' => 'text', 'value' => $vars[trim($var, '{} ')] ?? ''];
        }

        $response = Http::timeout(10)->acceptJson()->withHeaders(['authkey' => $cfg['authkey']])->post(self::WHATSAPP_URL, [
            'integrated_number' => $number,
            'content_type' => 'template',
            'payload' => [
                'messaging_product' => 'whatsapp',
                'type' => 'template',
                'template' => [
                    'name' => $name,
                    'language' => ['code' => ($cfg['whatsapp_language'] ?? '') ?: 'en', 'policy' => 'deterministic'],
                    'namespace' => (string) ($cfg['whatsapp_namespace'] ?? ''),
                    'to_and_components' => [['to' => [$to], 'components' => $components]],
                ],
            ],
        ]);

        return $this->outcome($response);
    }

    /** MSG91 answers 200 even for some errors, with {"type":"error","message":"..."} */
    protected function outcome(Response $response): array
    {
        $raw = $response->status().' '.mb_substr($response->body(), 0, 800);
        if (! $response->successful()) {
            return [false, 'MSG91 replied '.$response->status(), $raw];
        }
        if (($response->json('type') ?? $response->json('status')) === 'error' || $response->json('status') === 'fail') {
            return [false, 'MSG91: '.mb_substr((string) ($response->json('message') ?? $response->json('errors') ?? 'rejected'), 0, 150), $raw];
        }

        return [true, null, $raw];
    }
}
