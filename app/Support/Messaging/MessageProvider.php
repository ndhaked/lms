<?php

namespace App\Support\Messaging;

/**
 * One way of delivering SMS and/or WhatsApp messages (MSG91, a webhook, ...).
 * To add another provider: write a class that implements this, then list it in ProviderRegistry::CLASSES.
 * It then appears in Platform Settings -> Messaging, where an admin can switch SMS and WhatsApp to it.
 */
interface MessageProvider
{
    /** Short stable id stored in settings, e.g. "msg91". */
    public function key(): string;

    public function label(): string;

    /** @return list<string> the channels it can carry: "sms" and/or "whatsapp" */
    public function channels(): array;

    /**
     * Settings the admin fills in. type: text | url | select (give options) | secret (secrets are stored encrypted and never shown again).
     *
     * @return list<array{key: string, label: string, type: string, hint?: string, required?: bool}>
     */
    public function fields(): array;

    /**
     * Per-message settings the admin fills in for each automatic message (template id, template name, variables ...).
     * channel says which channel's column it belongs to, so only the channels switched to this provider are shown.
     *
     * @return list<array{key: string, label: string, channel: string, placeholder?: string}>
     */
    public function templateFields(): array;

    /** Does it need those per-message settings? */
    public function usesEventTemplates(): bool;

    /**
     * Hand one message to the provider.
     *
     * @param  array{event: ?string, vars: array<string, string>, library: ?string}  $context  vars are the template placeholder values
     * @return array{0: bool, 1: ?string, 2?: ?string} [accepted, error message, the provider's raw reply (kept in the log to find out why it failed)]
     */
    public function send(string $channel, string $to, string $message, array $context): array;
}
