<?php

namespace App\Reports\Platform;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Reports\Report;
use Illuminate\Support\Collection;

class PlanUsageReport extends Report
{
    public function key(): string { return 'plan-usage'; }
    public function title(): string { return 'Plan usage'; }
    public function description(): string { return 'How many libraries are on each plan, and what each plan has earned.'; }
    public function permission(): string { return 'reports.view'; }
    public function usesRange(): bool { return false; }

    public function headers(): array { return ['Plan', 'Plan status', 'Libraries (current)', 'Trial', 'Active', 'In grace', 'Expired', 'Suspended / cancelled', 'Revenue (approved)']; }

    public function collection(array $f): Collection
    {
        $current = Subscription::whereIn('id', Subscription::selectRaw('max(id)')->groupBy('library_id'))->get()->groupBy('subscription_plan_id');
        $revenue = SubscriptionPayment::where('status', SubscriptionPayment::APPROVED)->selectRaw('subscription_plan_id, sum(amount) total')->groupBy('subscription_plan_id')->pluck('total', 'subscription_plan_id');

        return SubscriptionPlan::orderBy('name')->get()->map(function ($plan) use ($current, $revenue) {
            $subs = $current->get($plan->id, collect());
            $by = $subs->groupBy(fn ($s) => $s->effectiveStatus())->map->count();

            return [$plan->name, ucfirst($plan->status), $subs->count(), $by['trial'] ?? 0, $by['active'] ?? 0, $by['grace'] ?? 0, $by['expired'] ?? 0,
                ($by['suspended'] ?? 0) + ($by['cancelled'] ?? 0), $this->money($revenue[$plan->id] ?? 0)];
        })->values();
    }
}
