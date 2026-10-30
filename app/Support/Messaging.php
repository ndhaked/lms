<?php

namespace App\Support;

use App\Models\CreditPurchase;
use App\Models\Library;
use App\Models\MessageLog;
use App\Models\Student;
use App\Models\WhatsappTemplate;
use App\Support\Messaging\MessageProvider;
use App\Support\Messaging\ProviderRegistry;
use Illuminate\Support\Facades\DB;

/**
 * SMS and WhatsApp sending.
 *
 * trigger(): something happened to a student (joined, paid, expiring ...). If the library switched that message on for a channel,
 * the matching template is filled in, handed to the provider the platform chose for that channel (see App\Support\Messaging\*),
 * and the credits are taken. A channel with no provider connected sends nothing and logs nothing.
 */
class Messaging
{
    public const SMS_SEGMENT = 160;

    /** event => [label, what it is, SMS template name, WhatsApp template name] */
    public const EVENTS = [
        'joined' => ['New membership', 'A student gets their first membership.', 'Joining', 'Membership'],
        'renewed' => ['Membership renewed', 'A student renews or gets another membership.', 'Renewed', 'Renewed'],
        'payment' => ['Payment received', 'A payment is recorded for a student.', 'Payment Received', 'Payment Received'],
        'expiring' => ['Membership ending soon', 'Sent once, when a membership is within your renewal window.', 'Renewal', 'Renewal'],
        'due' => ['Dues reminder', 'Sent weekly to students whose ended membership is not fully paid.', 'Due Payment', 'Due Payment Reminder'],
    ];

    /** The provider the platform connected for a channel, or null. */
    public static function provider(string $channel): ?MessageProvider
    {
        return ProviderRegistry::active($channel);
    }

    /** Is any (or this) channel connected to a provider? */
    public static function connected(?string $channel = null): bool
    {
        return $channel ? self::provider($channel) !== null : (self::provider('sms') || self::provider('whatsapp'));
    }

    /** Which channels a library switched on for an event. */
    public static function enabled(string $event, string $channel): bool
    {
        return (bool) (LibrarySettings::get('message_triggers')[$event][$channel] ?? false);
    }

    /** Credits one message needs: an SMS is billed per 160 characters, a WhatsApp message as one. */
    public static function creditsFor(string $channel, string $message): int
    {
        return $channel === 'sms' ? max(1, (int) ceil(mb_strlen($message) / self::SMS_SEGMENT)) : 1;
    }

    /** Digits only; a bare 10-digit number gets the India code. */
    public static function normalizeMobile(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile);

        return strlen($digits) === 10 ? '91'.$digits : $digits;
    }

    /** The text for an event on a channel: the library's own WhatsApp template, the system SMS template, or the built-in wording. */
    public static function template(string $event, string $channel): string
    {
        [, , $smsName, $waName] = self::EVENTS[$event];

        if ($channel === 'whatsapp') {
            $own = WhatsappTemplate::where('name', $waName)->value('message');

            return $own ?: collect(MessageTemplates::whatsappDefaults())->firstWhere('name', $waName)['message'] ?? '';
        }

        return collect(MessageTemplates::sms())->firstWhere('name', $smsName)['message'] ?? collect(MessageTemplates::smsDefaults())->firstWhere('name', $smsName)['message'] ?? '';
    }

    /**
     * React to something that happened to a student. Never throws: a failed message must not break the action that caused it.
     *
     * @param  array<string, scalar>  $vars  values for the template placeholders ({name} and {library} are added)
     */
    public static function trigger(string $event, Student $student, array $vars = [], ?string $ref = null): void
    {
        try {
            if (! isset(self::EVENTS[$event]) || ! self::connected() || blank($student->phone)) {
                return;
            }

            $library = Tenancy::library();
            $vars += ['name' => $student->name, 'library' => $library?->name ?? ''];
            $vars = array_map('strval', $vars);

            foreach (array_keys(CreditPurchase::CHANNELS) as $channel) {
                if (! self::connected($channel) || ! self::enabled($event, $channel)) {
                    continue;
                }
                if ($ref !== null && MessageLog::where('event', $event)->where('ref', $ref)->where('channel', $channel)->where('status', 'sent')->exists()) {
                    continue;   // already told about this
                }

                $text = MessageTemplates::fill(self::template($event, $channel), $vars);
                if ($text !== '') {
                    self::send($channel, $student->phone, $text, $student->id, $event, $ref, $vars);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Take the credits, hand the message to the provider, and log the outcome.
     * Not enough credits or a provider error log a failed row and take nothing.
     *
     * @param  array<string, string>  $vars  the placeholder values (providers that use approved templates send these as variables)
     */
    public static function send(string $channel, string $mobile, string $message, ?int $studentId = null, ?string $event = null, ?string $ref = null, array $vars = []): MessageLog
    {
        abort_unless(isset(CreditPurchase::CHANNELS[$channel]), 422);

        $count = self::creditsFor($channel, $message);
        $column = $channel.'_credits';
        $log = fn (string $status, ?string $error = null, ?string $response = null) => MessageLog::create([
            'student_id' => $studentId, 'channel' => $channel, 'mobile' => $mobile, 'count' => $count, 'message' => $message,
            'status' => $status, 'error' => $error, 'event' => $event, 'ref' => $ref,
            'provider' => self::provider($channel)?->key(), 'response' => $response !== null ? mb_substr($response, 0, 2000) : null,
        ]);

        $taken = DB::transaction(function () use ($column, $count) {
            $library = Library::whereKey(Tenancy::currentId())->lockForUpdate()->firstOrFail();
            if ((int) $library->{$column} < $count) {
                return false;
            }
            $library->decrement($column, $count);

            return true;
        });
        if (! $taken) {
            return $log('failed', 'Not enough credits');
        }

        [$ok, $error, $raw] = self::deliver($channel, $mobile, $message, $event, $vars) + [2 => null];
        if (! $ok) {
            Library::whereKey(Tenancy::currentId())->increment($column, $count);   // nothing was delivered, so give the credits back

            return $log('failed', $error, $raw);
        }

        return $log('sent', null, $raw);
    }

    /** Log an already delivered message and take its credits (kept for providers that report delivery themselves). */
    public static function record(string $channel, string $mobile, string $message, ?int $studentId = null): MessageLog
    {
        abort_unless(isset(CreditPurchase::CHANNELS[$channel]), 422);

        $count = self::creditsFor($channel, $message);
        $column = $channel.'_credits';

        return DB::transaction(function () use ($channel, $mobile, $message, $studentId, $count, $column) {
            $library = Library::whereKey(Tenancy::currentId())->lockForUpdate()->firstOrFail();
            $enough = (int) $library->{$column} >= $count;

            if ($enough) {
                $library->decrement($column, $count);
            }

            return MessageLog::create([
                'student_id' => $studentId, 'channel' => $channel, 'mobile' => $mobile, 'count' => $count, 'message' => $message,
                'status' => $enough ? 'sent' : 'failed', 'error' => $enough ? null : 'Not enough credits',
            ]);
        });
    }

    /**
     * Hand one message to the connected provider, without logging or charging (the "send test" button).
     *
     * @param  array<string, string>  $vars
     * @return array{0: bool, 1: ?string, 2?: ?string} [accepted, error, the provider's raw reply]
     */
    public static function deliverPublic(string $channel, string $mobile, string $message, ?string $event = null, array $vars = []): array
    {
        return self::deliver($channel, $mobile, $message, $event, $vars);
    }

    /** @return array{0: bool, 1: ?string} [delivered, error] */
    protected static function deliver(string $channel, string $mobile, string $message, ?string $event, array $vars = []): array
    {
        $provider = self::provider($channel);
        if (! $provider) {
            return [false, 'No messaging provider is connected for '.CreditPurchase::CHANNELS[$channel]];
        }

        try {
            return $provider->send($channel, $mobile, $message, ['event' => $event, 'vars' => $vars, 'library' => Tenancy::library()?->code]);
        } catch (\Throwable $e) {
            report($e);

            return [false, 'The provider failed', $e->getMessage()];
        }
    }
}
