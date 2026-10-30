<?php

namespace App\Actions\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\CreditPurchase;
use App\Models\Library;
use App\Models\OnlinePayment;
use App\Models\ReferralCoin;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\Pricing;
use App\Support\Razorpay;

/**
 * Starts a Razorpay payment: works out what is owed on our side, creates the Razorpay order for exactly that amount,
 * and returns the options the browser needs to open Razorpay Checkout. Nothing is paid or activated yet.
 */
class StartOnlinePayment
{
    /**
     * @return array{payment: OnlinePayment, options: array<string, mixed>}
     */
    public function subscription(Library $library, User $user, SubscriptionPlan $plan, string $cycle, bool $useCoins): array
    {
        $this->guard($user);

        $sub = Subscription::currentFor($library->id);
        if (! $sub || $sub->status === Subscription::CANCELLED) {
            throw new BusinessRuleException('This library has no subscription to pay for. Contact support.');
        }
        if ($plan->status !== 'active' || $plan->priceFor($cycle) === null) {
            throw new BusinessRuleException('That plan is not available for that billing period.');
        }

        $total = (float) Pricing::quote($library->id, $plan, $cycle)['total'];
        $coins = 0;
        if ($useCoins && BillingSettings::get('referral_enabled')) {
            $coins = ReferralCoin::maxRedeemable($library->id, $total);
            $total -= $coins * ReferralCoin::coinValue();
        }

        return $this->start($library, $user, ['purpose' => 'subscription', 'subscription_plan_id' => $plan->id, 'billing_cycle' => $cycle, 'coins_used' => $coins], $total, "{$plan->name} ".ucfirst($cycle));
    }

    public function credits(Library $library, User $user, string $channel, int $credits): array
    {
        $this->guard($user);

        $rate = (array) (BillingSettings::get('credit_rates')[$channel] ?? []);
        if (! isset(CreditPurchase::CHANNELS[$channel]) || ! in_array($credits, (array) ($rate['packs'] ?? []), true)) {
            throw new BusinessRuleException('That credit pack is not available.');
        }
        if (CreditPurchase::where('library_id', $library->id)->where('channel', $channel)->where('status', 'pending')->exists()) {
            throw new BusinessRuleException('You already have a '.CreditPurchase::CHANNELS[$channel].' credit payment waiting for review.');
        }

        return $this->start($library, $user, ['purpose' => 'credits', 'channel' => $channel, 'credits' => $credits], round($credits * (float) $rate['rate'], 2), number_format($credits).' '.CreditPurchase::CHANNELS[$channel].' credits');
    }

    protected function guard(User $user): void
    {
        abort_unless($user->can('billing.manage'), 403);

        if (! Razorpay::enabled()) {
            throw new BusinessRuleException('Online payment is not available right now.');
        }
    }

    /** @param  array<string, mixed>  $attrs */
    protected function start(Library $library, User $user, array $attrs, float $total, string $description): array
    {
        $total = round($total, 2);
        if ($total < 1) {
            throw new BusinessRuleException('The amount to pay online must be at least ₹1.');
        }

        // the row first, so the Razorpay order can carry its id and every order is traceable to a library
        $payment = OnlinePayment::create($attrs + ['library_id' => $library->id, 'user_id' => $user->id, 'amount' => number_format($total, 2, '.', ''), 'razorpay_order_id' => 'pending_'.bin2hex(random_bytes(10))]);

        try {
            $order = Razorpay::createOrder($payment->paise(), "LMS-{$payment->id}", ['library' => $library->code, 'online_payment' => (string) $payment->id, 'purpose' => $attrs['purpose']]);
        } catch (\RuntimeException $e) {
            $payment->delete();
            throw new BusinessRuleException($e->getMessage());
        }

        // never trust the order to differ from what we asked for
        if ((int) ($order['amount'] ?? 0) !== $payment->paise() || ($order['currency'] ?? '') !== 'INR') {
            $payment->delete();
            throw new BusinessRuleException('Razorpay returned an unexpected order. Please try again.');
        }
        $payment->update(['razorpay_order_id' => $order['id']]);

        return ['payment' => $payment, 'options' => [
            'key' => Razorpay::keyId(),
            'order_id' => $order['id'],
            'amount' => $payment->paise(),
            'currency' => 'INR',
            'name' => BillingSettings::get('app_name') ?: config('app.name'),
            'description' => $description,
            'prefill' => ['name' => $user->name, 'email' => $user->email, 'contact' => (string) $user->phone],
            'notes' => ['library' => $library->code],
            'theme' => ['color' => '#1b5033'],
        ]];
    }
}
