<?php

namespace App\Reports\Platform;

use App\Models\SubscriptionPayment;
use App\Reports\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Revenue = APPROVED subscription payments only. Student fees collected by libraries are never part of this. */
class SubscriptionRevenueReport extends Report
{
    public function key(): string { return 'subscription-revenue'; }
    public function title(): string { return 'Subscription revenue'; }
    public function description(): string { return 'Approved subscription payments in the period (excludes students\' fees).'; }
    public function permission(): string { return 'reports.view'; }

    public function headers(): array { return ['Approved on', 'Invoice', 'Code', 'Library', 'Plan', 'Cycle', 'Amount', 'Reference', 'Period covered']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return SubscriptionPayment::query()->with(['library', 'plan', 'subscription', 'invoice'])->where('status', SubscriptionPayment::APPROVED)
            ->whereDate('reviewed_at', '>=', $from)->whereDate('reviewed_at', '<=', $until)->orderByDesc('reviewed_at')->orderBy('id');
    }

    public function row(Model $m): array
    {
        return [$this->date($m->reviewed_at), $m->invoice?->invoice_no, $m->library->code, $m->library->name, $m->plan?->name ?? $m->subscription->plan_name,
            ucfirst($m->billing_cycle), $this->money($m->amount), $m->reference,
            $m->period_starts_on ? $this->date($m->period_starts_on).' – '.$this->date($m->period_ends_on) : ''];
    }

    public function summary(array $f): array
    {
        return ['Revenue' => '₹'.$this->money($this->query($f)->sum('amount')), 'Payments' => (string) $this->query($f)->count()];
    }
}
