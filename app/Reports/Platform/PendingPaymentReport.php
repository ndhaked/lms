<?php

namespace App\Reports\Platform;

use App\Models\SubscriptionPayment;
use App\Reports\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PendingPaymentReport extends Report
{
    public function key(): string { return 'pending-payments'; }
    public function title(): string { return 'Pending payments'; }
    public function description(): string { return 'Subscription payments waiting for a decision or for more information.'; }
    public function permission(): string { return 'reports.view'; }
    public function usesRange(): bool { return false; }
    public function statusOptions(): ?array { return ['pending' => 'Pending review', 'info_requested' => 'Waiting for information']; }

    public function headers(): array { return ['Submitted', 'Code', 'Library', 'Plan', 'Cycle', 'Amount', 'Method', 'Reference', 'Status', 'Waiting (days)']; }

    public function query(array $f): ?Builder
    {
        return SubscriptionPayment::query()->with(['library', 'plan', 'subscription'])->whereIn('status', ['pending', 'info_requested'])
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))->orderBy('created_at')->orderBy('id');
    }

    public function row(Model $m): array
    {
        return [$this->date($m->created_at), $m->library->code, $m->library->name, $m->plan?->name ?? $m->subscription->plan_name, ucfirst($m->billing_cycle),
            $this->money($m->amount), SubscriptionPayment::METHODS[$m->method] ?? $m->method, $m->reference, SubscriptionPayment::STATUSES[$m->status], (int) $m->created_at->diffInDays(now())];
    }

    public function summary(array $f): array
    {
        return ['Waiting' => (string) $this->query($f)->count(), 'Amount' => '₹'.$this->money($this->query($f)->sum('amount'))];
    }
}
