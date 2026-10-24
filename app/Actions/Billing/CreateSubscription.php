<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\AuthorizesPlatform;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;

class CreateSubscription
{
    use AuthorizesPlatform;

    /**
     * Gives a library a plan. With $trial it starts as a trial for the plan's trial days; otherwise it is
     * "pending" until the first payment is approved. $authorize is false only for registration, which has
     * already been authorised as a whole.
     */
    public function __invoke(Library $library, SubscriptionPlan $plan, string $cycle = 'monthly', bool $trial = false, bool $authorize = true): Subscription
    {
        if ($authorize) {
            $this->authorizePlatform('subscriptions.manage');
        }

        if ($plan->status !== 'active') {
            throw new BusinessRuleException("Plan {$plan->name} is not active.");
        }
        if (! in_array($cycle, $plan->cycles(), true)) {
            throw new BusinessRuleException("{$plan->name} is not sold on a {$cycle} cycle.");
        }
        if ($trial && $plan->trial_days < 1) {
            throw new BusinessRuleException("{$plan->name} has no trial period.");
        }

        return DB::transaction(function () use ($library, $plan, $cycle, $trial) {
            $current = Subscription::where('library_id', $library->id)->where('status', '!=', Subscription::CANCELLED)->lockForUpdate()->latest('id')->first();
            if ($current) {
                throw new BusinessRuleException('This library already has a subscription. Change its plan instead.');
            }

            $s = new Subscription(['library_id' => $library->id, 'status' => $trial ? Subscription::TRIAL : Subscription::PENDING]);
            $s->applyPlan($plan, $cycle);

            if ($trial) {
                $s->starts_on = today();
                $s->trial_ends_on = today()->addDays($plan->trial_days)->subDay();   // N trial days including today
                $s->expires_on = $s->trial_ends_on;
            }
            $s->save();

            SubscriptionEvent::log($s, 'created', null, $s->status, null, ['plan' => $plan->name, 'cycle' => $cycle, 'trial' => $trial]);
            AuditLog::record('subscription.created', $s, ['plan' => $plan->name, 'status' => $s->status], $library->id);

            return $s;
        });
    }
}
