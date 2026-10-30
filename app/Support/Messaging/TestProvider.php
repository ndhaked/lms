<?php

namespace App\Support\Messaging;

/** Accepts every message without delivering it: for trying the flow out. Credits are still used and the log still fills. */
class TestProvider implements MessageProvider
{
    public function key(): string
    {
        return 'test';
    }

    public function label(): string
    {
        return 'Test mode (nothing is delivered)';
    }

    public function channels(): array
    {
        return ['sms', 'whatsapp'];
    }

    public function fields(): array
    {
        return [];
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
        return [true, null];
    }
}
