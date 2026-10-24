<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\AuthorizesPlatform;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Support\Entitlements;
use App\Support\Notifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Manual changes by platform staff. Every one needs a reason and records who, when, the previous
 * and the new value (subscription history + audit log). Nothing here deletes data.
 */
class ManageSubscription
{
    use AuthorizesPlatform;

    public function suspend(Subscription $s, string $reason): Subscription
    {
        return $this->change($s, 'suspended', $reason, [Subscription::TRIAL, Subscription::ACTIVE, Subscription::EXPIRED, Subscription::PENDING], function (Subscription $s) {});
    }

    public function cancel(Subscription $s, string $reason): Subscription
    {
        return $this->change($s, 'cancelled', $reason, [Subscription::TRIAL, Subscription::ACTIVE, Subscription::EXPIRED, Subscription::PENDING, Subscription::SUSPENDED],
            function (Subscription $s) {
                $s->cancelled_at = now();
            });
    }

    /** Back from suspension to whatever the dates say: active, trial, expired or pending. */
    public function reactivate(Subscription $s, string $reason): Subscription
    {
        return $this->change($s, 'reactivated', $reason, [Subscription::SUSPENDED], function (Subscription $s) {
            $paidBefore = SubscriptionPayment::where('subscription_id', $s->id)->where('status', SubscriptionPayment::APPROVED)->exists();

            $s->status = match (true) {
                $paidBefore && $s->expires_on && $s->expires_on->gte(today()) => Subscription::ACTIVE,
                ! $paidBefore && $s->trial_ends_on && $s->trial_ends_on->gte(today()) => Subscription::TRIAL,
                ! $paidBefore && ! $s->trial_ends_on => Subscription::PENDING,
                default => Subscription::EXPIRED,
            };
        });
    }

    /** Switch on access until $until without a payment (offline arrangement, goodwill ...). */
    public function activate(Subscription $s, CarbonInterface $until, string $reason): Subscription
    {
        if ($until->lt(today())) {
            throw new BusinessRuleException('The new expiry date must be today or later.');
        }

        return $this->change($s, 'activated', $reason, [Subscription::PENDING, Subscription::TRIAL, Subscription::EXPIRED], function (Subscription $s) use ($until) {
            $s->status = Subscription::ACTIVE;
            $s->starts_on ??= today();
            $s->expires_on = $until->copy()->startOfDay();
        }, ['expires_on' => $until->toDateString()]);
    }

    public function extend(Subscription $s, CarbonInterface $newExpiry, string $reason): Subscription
    {
        $this->authorizePlatform('subscriptions.manage');

        if (blank($reason)) {
            throw new BusinessRuleException('A reason is required to extend a subscription.');
        }

        $s = DB::transaction(function () use ($s, $newExpiry, $reason) {
            $s = Subscription::query()->whereKey($s->id)->lockForUpdate()->firstOrFail();

            if (in_array($s->status, [Subscription::CANCELLED, Subscription::SUSPENDED], true)) {
                throw new BusinessRuleException("A {$s->status} subscription cannot be extended. Reactivate it first.");
            }
            $current = $s->coveredUntil();
            if ($current && $newExpiry->lte($current)) {
                throw new BusinessRuleException('The new date must be after the current expiry ('.$current->format('d M Y').').');
            }

            $from = $s->status;
            $previous = $current?->toDateString();

            if ($s->status === Subscription::TRIAL) {
                $s->trial_ends_on = $newExpiry->copy()->startOfDay();
            }
            $s->expires_on = $newExpiry->copy()->startOfDay();
            if ($s->status === Subscription::EXPIRED || $s->status === Subscription::PENDING) {
                $s->status = Subscription::ACTIVE;
                $s->starts_on ??= today();
            }
            $s->save();

            SubscriptionEvent::log($s, 'extended', $from, $s->status, $reason, ['previous_expiry' => $previous, 'new_expiry' => $s->expires_on->toDateString()]);
            AuditLog::record('subscription.extended', $s, ['from' => $previous, 'to' => $s->expires_on->toDateString(), 'reason' => $reason], $s->library_id);

            return $s;
        });

        Entitlements::flush();
        Notifier::library($s->library, 'billing.view', 'subscription.extended', 'Subscription extended', "Your subscription now runs until {$s->expires_on->format('d M Y')}.", route('app.subscription.index', ['library' => $s->library->slug]));

        return $s;
    }

    /**
     * @param  string  $when  'now' updates limits and price immediately; 'renewal' takes effect at the next approved payment.
     */
    public function changePlan(Subscription $s, SubscriptionPlan $plan, string $cycle, string $when, string $reason): Subscription
    {
        $this->authorizePlatform('subscriptions.manage');

        if (blank($reason)) {
            throw new BusinessRuleException('A reason is required to change a plan.');
        }
        if ($plan->status !== 'active') {
            throw new BusinessRuleException("Plan {$plan->name} is not active.");
        }
        if (! in_array($cycle, $plan->cycles(), true)) {
            throw new BusinessRuleException("{$plan->name} is not sold on a {$cycle} cycle.");
        }
        if (! in_array($when, ['now', 'renewal'], true)) {
            throw new BusinessRuleException('Choose when the change applies.');
        }

        $s = DB::transaction(function () use ($s, $plan, $cycle, $when, $reason) {
            $s = Subscription::query()->whereKey($s->id)->lockForUpdate()->firstOrFail();

            if ($s->status === Subscription::CANCELLED) {
                throw new BusinessRuleException('A cancelled subscription cannot change plan.');
            }

            $previous = ['plan' => $s->plan_name, 'cycle' => $s->billing_cycle];

            if ($when === 'now') {
                // Never downgrade below what the library already uses.
                foreach (['students' => $plan->max_students, 'seats' => $plan->max_seats, 'staff' => $plan->max_staff] as $resource => $limit) {
                    $used = Entitlements::usage($resource, $s->library_id);
                    if ($limit !== null && $used > $limit) {
                        throw new BusinessRuleException("The library has {$used} {$resource}, more than {$plan->name} allows ({$limit}).");
                    }
                }
                $s->applyPlan($plan, $cycle);
                $s->next_plan_id = null;
            } else {
                $s->next_plan_id = $plan->id;
            }
            $s->save();

            SubscriptionEvent::log($s, 'plan_changed', $s->status, $s->status, $reason, $previous + ['new_plan' => $plan->name, 'new_cycle' => $cycle, 'applies' => $when]);
            AuditLog::record('subscription.plan_changed', $s, $previous + ['to' => $plan->name, 'applies' => $when, 'reason' => $reason], $s->library_id);

            return $s;
        });

        Entitlements::flush();

        return $s;
    }

    protected function change(Subscription $s, string $event, string $reason, array $allowedFrom, \Closure $apply, array $extra = []): Subscription
    {
        $this->authorizePlatform('subscriptions.manage');

        if (blank($reason)) {
            throw new BusinessRuleException('A reason is required for this change.');
        }

        $s = DB::transaction(function () use ($s, $event, $reason, $allowedFrom, $apply, $extra) {
            $s = Subscription::query()->whereKey($s->id)->lockForUpdate()->firstOrFail();

            if (! in_array($s->status, $allowedFrom, true)) {
                throw new BusinessRuleException("A {$s->status} subscription cannot be {$event}.");
            }

            $from = $s->status;
            $s->status = match ($event) { 'suspended' => Subscription::SUSPENDED, 'cancelled' => Subscription::CANCELLED, default => $s->status };
            $apply($s);
            $s->save();

            SubscriptionEvent::log($s, $event, $from, $s->status, $reason, $extra);
            AuditLog::record("subscription.{$event}", $s, ['from' => $from, 'to' => $s->status, 'reason' => $reason] + $extra, $s->library_id);

            return $s;
        });

        Entitlements::flush();
        Notifier::library($s->library, 'billing.view', "subscription.{$event}", 'Subscription '.$event, $reason, route('app.subscription.index', ['library' => $s->library->slug]));

        return $s;
    }
}
