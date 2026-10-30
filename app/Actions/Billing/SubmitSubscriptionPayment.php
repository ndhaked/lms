<?php

namespace App\Actions\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentProof;
use App\Models\SubscriptionPlan;
use App\Support\BillingSettings;
use App\Support\Notifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SubmitSubscriptionPayment
{
    /**
     * A library owner reports an offline payment (bank transfer / UPI ...) for platform review.
     * Nothing about the subscription changes here: only an approval by platform staff does that.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files  proof of payment
     */
    public function __invoke(
        Library $library,
        ?SubscriptionPlan $plan,
        string $cycle,
        string $reference,
        string|float $amount,
        string $method,
        CarbonInterface $paidOn,
        ?string $note = null,
        array $files = [],
        int $coins = 0,
    ): SubscriptionPayment {
        abort_unless(auth()->user()?->can('billing.manage'), 403);

        $subscription = Subscription::currentFor($library->id);
        if (! $subscription || $subscription->status === Subscription::CANCELLED) {
            throw new BusinessRuleException('This library has no subscription to pay for. Contact support.');
        }

        $plan ??= $subscription->plan;
        if (! $plan || $plan->status !== 'active') {
            throw new BusinessRuleException('That plan is not available.');
        }
        $plan->priceFor($cycle) ?? throw new BusinessRuleException("{$plan->name} is not sold on a {$cycle} cycle.");
        $quote = \App\Support\Pricing::quote($library->id, $plan, $cycle);
        $price = $quote['total'];

        // Referral coins pay for part of the price.
        if ($coins > 0) {
            if (! BillingSettings::get('referral_enabled') || $coins > \App\Models\ReferralCoin::maxRedeemable($library->id, (float) $price)) {
                throw new BusinessRuleException('You cannot use that many coins on this plan.');
            }
            $off = number_format($coins * \App\Models\ReferralCoin::coinValue(), 2, '.', '');
            $price = bcsub($price, $off, 2);
            $quote['lines'][] = ['label' => "Referral coins ({$coins})", 'amount' => '-'.$off];
        }

        $amount = number_format((float) $amount, 2, '.', '');
        $reference = trim($reference);

        if (! in_array($method, (array) BillingSettings::get('payment_methods'), true)) {
            throw new BusinessRuleException('That payment method is not accepted.');
        }
        if ($reference === '' || mb_strlen($reference) > 100) {
            throw new BusinessRuleException('Enter the payment reference (up to 100 characters).');
        }
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new BusinessRuleException('Enter the amount you paid.');
        }
        if (BillingSettings::get('require_exact_amount') && bccomp($amount, $price, 2) !== 0) {
            throw new BusinessRuleException("The amount must be exactly ₹{$price} for the {$cycle} {$plan->name} plan".(count($quote['lines']) > 1 ? ' including your add-on modules.' : '.'));
        }
        if ($paidOn->isFuture()) {
            throw new BusinessRuleException('The payment date cannot be in the future.');
        }
        if (BillingSettings::get('proof_required') && count($files) === 0) {
            throw new BusinessRuleException('Attach proof of payment (a screenshot or receipt).');
        }

        $payment = DB::transaction(function () use ($library, $subscription, $plan, $cycle, $reference, $amount, $method, $paidOn, $note, $files, $quote, $coins) {
            // Serialise submissions per subscription so two tabs cannot both slip through the checks below.
            Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if (SubscriptionPayment::where('subscription_id', $subscription->id)->whereIn('status', [SubscriptionPayment::PENDING, SubscriptionPayment::INFO_REQUESTED])->exists()) {
                throw new BusinessRuleException('You already have a payment waiting for review. Wait for the decision, or answer the question we asked.');
            }
            if (SubscriptionPayment::where('library_id', $library->id)->where('reference', $reference)->where('status', '!=', SubscriptionPayment::REJECTED)->exists()) {
                throw new BusinessRuleException('That payment reference has already been submitted.');
            }

            $payment = SubscriptionPayment::create([
                'subscription_id' => $subscription->id, 'library_id' => $library->id, 'subscription_plan_id' => $plan->id,
                'billing_cycle' => $cycle, 'reference' => $reference, 'amount' => $amount, 'breakdown' => $quote['lines'], 'method' => $method,
                'paid_on' => $paidOn->toDateString(), 'note' => $note ?: null, 'submitted_by' => auth()->id(), 'coins_used' => $coins,
            ]);

            if ($coins > 0) {
                // reserve the coins now; they come back if the payment is rejected
                \App\Models\ReferralCoin::create(['library_id' => $library->id, 'coins' => -$coins, 'kind' => 'redeemed', 'description' => 'Used on a subscription payment', 'subscription_payment_id' => $payment->id]);
            }

            foreach ($files as $file) {
                $payment->proofs()->create([
                    'path' => $file->store("subscription-proofs/{$library->id}", 'local'),   // private disk
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                    'mime' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(),
                ]);
            }

            SubscriptionEvent::log($subscription, 'payment_submitted', null, null, null, ['reference' => $reference, 'amount' => $amount, 'cycle' => $cycle]);
            AuditLog::record('subscription.payment_submitted', $payment, ['amount' => $amount, 'reference' => $reference], $library->id);

            return $payment;
        });

        // Tell the people who approve payments that one is waiting.
        Notifier::platform('payments.approve', 'subscription.payment_submitted', 'Subscription payment to review',
            "{$library->name} submitted ₹{$amount} ({$reference}).", route('admin.payment-approvals.index'));

        return $payment;
    }
}
