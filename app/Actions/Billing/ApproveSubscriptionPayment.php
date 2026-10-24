<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\AuthorizesPlatform;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Support\Entitlements;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;

class ApproveSubscriptionPayment
{
    use AuthorizesPlatform;

    /**
     * Accepts a reported payment: extends the subscription, issues the invoice, records history.
     * A payment can be approved exactly once (the row is locked and its status re-checked).
     * Subscription status and payment status stay separate: a suspended subscription is NOT reactivated
     * by a payment; platform staff do that explicitly.
     */
    public function __invoke(SubscriptionPayment $payment, ?string $note = null): SubscriptionPayment
    {
        $this->authorizePlatform('payments.approve');

        $payment = DB::transaction(function () use ($payment, $note) {
            $payment = SubscriptionPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== SubscriptionPayment::PENDING) {
                throw new BusinessRuleException('Only a payment that is pending review can be approved (current status: '.str_replace('_', ' ', $payment->status).').');
            }

            $s = Subscription::query()->whereKey($payment->subscription_id)->lockForUpdate()->firstOrFail();
            if ($s->status === Subscription::CANCELLED) {
                throw new BusinessRuleException('This subscription has been cancelled.');
            }

            // The new period starts right after the paid-up-to date if that is still ahead, otherwise today.
            $covered = in_array($s->status, [Subscription::TRIAL, Subscription::ACTIVE], true) ? $s->coveredUntil() : null;
            $start = $covered && $covered->gte(today()) ? $covered->copy()->addDay() : today();
            $end = Subscription::periodEnd($start, $payment->billing_cycle);

            // Renewal is when plan edits take effect: take the plan's CURRENT price, limits and modules now.
            $plan = $s->nextPlan ?? $payment->plan ?? $s->plan;
            $from = $s->status;
            if ($plan) {
                $s->applyPlan($plan, $payment->billing_cycle);
                $s->next_plan_id = null;
            }

            $s->fill(['starts_on' => $s->starts_on ?? $start, 'expires_on' => $end, 'is_complimentary' => false]);
            if ($s->status !== Subscription::SUSPENDED) {
                $s->status = Subscription::ACTIVE;
            }
            $s->save();

            $payment->forceFill([
                'status' => SubscriptionPayment::APPROVED, 'reviewed_by' => auth()->id(), 'reviewed_at' => now(),
                'review_note' => $note ?: null, 'period_starts_on' => $start, 'period_ends_on' => $end,
            ])->save();

            $invoice = SubscriptionInvoice::create([
                'library_id' => $s->library_id, 'subscription_id' => $s->id, 'subscription_payment_id' => $payment->id,
                'amount' => $payment->amount, 'status' => 'paid', 'issued_on' => today(),
                'period_starts_on' => $start, 'period_ends_on' => $end,
                'description' => "{$s->plan_name} — ".ucfirst($payment->billing_cycle).' subscription'.(count((array) $payment->breakdown) > 1 ? ' + add-ons' : ''),
                'lines' => $payment->breakdown ?: null,
            ]);
            $invoice->forceFill(['invoice_no' => 'INV-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT)])->save();

            SubscriptionEvent::log($s, 'payment_approved', $from, $s->status, $note, [
                'payment' => $payment->id, 'amount' => (string) $payment->amount, 'period' => [$start->toDateString(), $end->toDateString()],
                'invoice' => $invoice->invoice_no,
            ]);
            if ($from !== $s->status) {
                SubscriptionEvent::log($s, 'activated', $from, $s->status);
            }
            AuditLog::record('subscription.payment_approved', $payment, [
                'library' => $s->library_id, 'amount' => (string) $payment->amount, 'invoice' => $invoice->invoice_no, 'expires_on' => $end->toDateString(),
            ], $s->library_id);

            return $payment->setRelation('subscription', $s);
        });

        Entitlements::flush();
        Notifier::library($payment->library, 'billing.view', 'subscription.payment_approved', 'Subscription payment approved',
            "Your {$payment->subscription->plan_name} subscription is paid until ".$payment->period_ends_on->format('d M Y').'.',
            route('app.subscription.index', ['library' => $payment->library->slug]));

        return $payment;
    }
}
