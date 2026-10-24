<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\AuthorizesPlatform;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Support\Entitlements;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Gives a library complimentary (free) access for a number of days, with the limits and modules of a plan.
 * Works for a library with no subscription, or one that is pending, on trial, expired, or already on a free period
 * (which is then extended). Paid, suspended and cancelled subscriptions are never touched.
 * When the free period ends the normal grace/expiry rules apply, and an approved payment turns it into a paid plan.
 */
class GrantFreePeriod
{
    use AuthorizesPlatform;

    public const PRESETS = [15 => '15 days', 30 => '1 month (30 days)', 60 => '2 months (60 days)'];

    public function __invoke(Library $library, SubscriptionPlan $plan, int $days, string $reason): Subscription
    {
        $this->authorizePlatform('subscriptions.manage');

        if ($days < 1 || $days > 730) {
            throw new BusinessRuleException('Free access must be between 1 and 730 days.');
        }
        if (blank($reason)) {
            throw new BusinessRuleException('A reason is required to grant free access.');
        }
        if ($plan->status !== 'active') {
            throw new BusinessRuleException("Plan {$plan->name} is not active.");
        }

        $s = DB::transaction(function () use ($library, $plan, $days, $reason) {
            $s = Subscription::where('library_id', $library->id)->where('status', '!=', Subscription::CANCELLED)->lockForUpdate()->latest('id')->first();

            $from = $s?->status;
            if ($s && $s->status === Subscription::SUSPENDED) {
                throw new BusinessRuleException('This subscription is suspended. Reactivate it first.');
            }
            if ($s && $s->status === Subscription::ACTIVE && ! $s->is_complimentary && $s->expires_on?->gte(today())) {
                throw new BusinessRuleException('This library has a paid subscription that is still running.');
            }

            // Extending a running free period continues from its end; anything else starts today.
            $running = $s && $s->is_complimentary && $s->status === Subscription::ACTIVE && $s->expires_on?->gte(today());
            $start = $running ? $s->expires_on->copy()->addDay() : today();
            $until = $start->copy()->addDays($days - 1);

            if (! $s) {
                $s = new Subscription(['library_id' => $library->id]);
                $s->applyPlan($plan, 'monthly');
                $s->starts_on = today();
            } elseif (! $running) {
                $s->applyPlan($plan, $s->billing_cycle);
                $s->starts_on = today();
            }

            $s->fill(['status' => Subscription::ACTIVE, 'is_complimentary' => true, 'price' => 0, 'expires_on' => $until, 'trial_ends_on' => null]);
            $s->save();

            SubscriptionEvent::log($s, 'free_period', $from, $s->status, $reason, ['days' => $days, 'plan' => $plan->name, 'until' => $until->toDateString()]);
            AuditLog::record('subscription.free_period', $s, ['days' => $days, 'plan' => $plan->name, 'until' => $until->toDateString(), 'reason' => $reason], $library->id);

            return $s;
        });

        Entitlements::flush();
        Notifier::library($library, 'billing.view', "subscription.free_period.{$s->id}.{$s->expires_on->toDateString()}", 'Free access granted', "You have free access until {$s->expires_on->format('d M Y')}.", route('app.subscription.index', ['library' => $library->slug]));

        return $s;
    }
}
