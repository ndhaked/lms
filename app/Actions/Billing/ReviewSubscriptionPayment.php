<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\AuthorizesPlatform;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPayment;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;

/** The two review outcomes that do NOT change the subscription: rejecting, and asking for more information. */
class ReviewSubscriptionPayment
{
    use AuthorizesPlatform;

    public function reject(SubscriptionPayment $payment, string $reason): SubscriptionPayment
    {
        $this->authorizePlatform('payments.approve');

        if (blank($reason)) {
            throw new BusinessRuleException('A reason is required to reject a payment.');
        }

        $payment = $this->transition($payment, SubscriptionPayment::REJECTED, $reason, [SubscriptionPayment::PENDING, SubscriptionPayment::INFO_REQUESTED]);

        if ($payment->coins_used > 0) {
            \App\Models\ReferralCoin::create(['library_id' => $payment->library_id, 'coins' => $payment->coins_used, 'kind' => 'returned', 'description' => 'Payment rejected: coins returned', 'subscription_payment_id' => $payment->id]);
        }

        Notifier::library($payment->library, 'billing.view', 'subscription.payment_rejected', 'Subscription payment rejected', $reason,
            route('app.subscription.index', ['library' => $payment->library->slug]));

        return $payment;
    }

    public function requestInfo(SubscriptionPayment $payment, string $question): SubscriptionPayment
    {
        $this->authorizePlatform('payments.approve');

        if (blank($question)) {
            throw new BusinessRuleException('Say what information you need.');
        }

        $payment = $this->transition($payment, SubscriptionPayment::INFO_REQUESTED, $question, [SubscriptionPayment::PENDING]);

        Notifier::library($payment->library, 'billing.manage', 'subscription.payment_info_requested', 'We need more information about your payment', $question,
            route('app.subscription.index', ['library' => $payment->library->slug]));

        return $payment;
    }

    protected function transition(SubscriptionPayment $payment, string $to, string $note, array $allowedFrom): SubscriptionPayment
    {
        return DB::transaction(function () use ($payment, $to, $note, $allowedFrom) {
            $payment = SubscriptionPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($payment->status, $allowedFrom, true)) {
                throw new BusinessRuleException('This payment can no longer be changed that way (status: '.str_replace('_', ' ', $payment->status).').');
            }

            $from = $payment->status;
            $payment->forceFill(['status' => $to, 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_note' => $note])->save();

            SubscriptionEvent::log($payment->subscription, 'payment_'.$to, $from, $to, $note, ['payment' => $payment->id]);
            AuditLog::record("subscription.payment_{$to}", $payment, ['reason' => $note, 'amount' => (string) $payment->amount], $payment->library_id);

            return $payment;
        });
    }
}
