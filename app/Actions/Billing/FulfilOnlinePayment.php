<?php

namespace App\Actions\Billing;

use App\Models\AuditLog;
use App\Models\CreditPurchase;
use App\Models\OnlinePayment;
use App\Models\ReferralCoin;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Support\Pricing;
use App\Support\Razorpay;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a paid Razorpay order into what the library bought (an activated plan, or credits).
 *
 * It is called from two places, the browser coming back from Checkout and the webhook, and may be called many times for the
 * same order. The order row is locked and its status re-read inside the transaction, so the purchase is made exactly once.
 * Before anything is granted the payment is checked against Razorpay: right order, right amount, right currency, really paid.
 */
class FulfilOnlinePayment
{
    /**
     * @param  array<string, mixed>  $rzp  the payment as fetched from Razorpay (see Razorpay::fetchPayment)
     * @return OnlinePayment the order, with its final status
     */
    public function __invoke(OnlinePayment $order, array $rzp): OnlinePayment
    {
        return DB::transaction(function () use ($order, $rzp) {
            $order = OnlinePayment::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === 'paid') {
                return $order;                                         // already done: webhook and browser raced, or a retry
            }

            $paymentId = (string) ($rzp['id'] ?? '');
            $problem = $this->problem($order, $rzp, $paymentId);

            if ($problem === 'not_paid_yet') {
                return $order;                                         // authorized but not captured, or still processing: wait
            }
            if ($problem !== null) {
                // money may have moved but it does not match the order: grant nothing, record why, and let a person look
                $order->update(['status' => 'failed', 'failure_reason' => $problem]);
                Log::warning('Razorpay payment did not match its order', ['online_payment' => $order->id, 'reason' => $problem]);

                return $order;
            }

            Tenancy::run($order->library, function () use ($order, $paymentId) {
                $order->purpose === 'subscription' ? $this->grantSubscription($order, $paymentId) : $this->grantCredits($order, $paymentId);
            });

            $order->update(['status' => 'paid', 'razorpay_payment_id' => $paymentId, 'fulfilled_at' => now(), 'failure_reason' => null]);
            AuditLog::record('online_payment.paid', $order, ['purpose' => $order->purpose, 'amount' => (string) $order->amount, 'payment' => $paymentId], $order->library_id);

            return $order;
        });
    }

    /** null = fine; 'not_paid_yet' = wait; anything else = why it was refused. */
    protected function problem(OnlinePayment $order, array $rzp, string $paymentId): ?string
    {
        if (! Razorpay::validPaymentId($paymentId)) {
            return 'Missing or invalid payment id';
        }
        if (($rzp['order_id'] ?? null) !== $order->razorpay_order_id) {
            return 'Payment belongs to a different order';
        }
        if ((int) ($rzp['amount'] ?? -1) !== $order->paise() || ($rzp['currency'] ?? '') !== $order->currency) {
            return 'Amount or currency does not match the order';
        }
        if (OnlinePayment::where('razorpay_payment_id', $paymentId)->where('id', '!=', $order->id)->exists()) {
            return 'Payment id already used for another order';
        }

        $status = (string) ($rzp['status'] ?? '');
        if ($status === 'authorized') {
            return Razorpay::capture($paymentId, $order->paise()) ? null : 'not_paid_yet';   // capture it ourselves when the account is on manual capture
        }
        if ($status === 'captured') {
            return null;
        }
        if (in_array($status, ['failed', 'refunded'], true)) {
            return "Razorpay reports the payment as {$status}";
        }

        return 'not_paid_yet';
    }

    protected function grantSubscription(OnlinePayment $order, string $paymentId): void
    {
        $library = $order->library;
        $sub = Subscription::currentFor($library->id);
        $plan = $order->plan;
        $quote = Pricing::quote($library->id, $plan, $order->billing_cycle);
        $lines = $quote['lines'];

        if ($order->coins_used > 0) {
            $lines[] = ['label' => "Referral coins ({$order->coins_used})", 'amount' => '-'.number_format($order->coins_used * ReferralCoin::coinValue(), 2, '.', '')];
        }

        $payment = SubscriptionPayment::create([
            'subscription_id' => $sub->id, 'library_id' => $library->id, 'subscription_plan_id' => $plan->id, 'billing_cycle' => $order->billing_cycle,
            'reference' => $paymentId, 'amount' => $order->amount, 'breakdown' => $lines, 'method' => 'razorpay', 'paid_on' => today()->toDateString(),
            'note' => "Paid online with Razorpay (order {$order->razorpay_order_id}).", 'submitted_by' => $order->user_id, 'coins_used' => $order->coins_used,
        ]);

        if ($order->coins_used > 0) {
            ReferralCoin::create(['library_id' => $library->id, 'coins' => -$order->coins_used, 'kind' => 'redeemed', 'description' => 'Used on an online subscription payment', 'subscription_payment_id' => $payment->id]);
        }

        app(ApproveSubscriptionPayment::class)->system($payment, 'Paid online with Razorpay; approved automatically.');
        $order->subscription_payment_id = $payment->id;
    }

    protected function grantCredits(OnlinePayment $order, string $paymentId): void
    {
        $rate = (string) ((array) (\App\Support\BillingSettings::get('credit_rates')[$order->channel] ?? []))['rate'];

        $purchase = CreditPurchase::create([
            'library_id' => $order->library_id, 'user_id' => $order->user_id, 'channel' => $order->channel, 'credits' => $order->credits, 'rate' => $rate,
            'amount' => $order->amount, 'method' => 'razorpay', 'reference' => $paymentId, 'paid_on' => today()->toDateString(), 'note' => "Paid online with Razorpay (order {$order->razorpay_order_id}).",
        ]);

        app(ReviewCreditPurchase::class)->system($purchase, 'Paid online with Razorpay; approved automatically.');
        $order->credit_purchase_id = $purchase->id;
    }
}
