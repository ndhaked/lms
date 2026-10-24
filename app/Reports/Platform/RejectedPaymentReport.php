<?php

namespace App\Reports\Platform;

use App\Models\SubscriptionPayment;
use App\Reports\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RejectedPaymentReport extends Report
{
    public function key(): string { return 'rejected-payments'; }
    public function title(): string { return 'Rejected payments'; }
    public function description(): string { return 'Subscription payments that were rejected, with the reason given.'; }
    public function permission(): string { return 'reports.view'; }

    public function headers(): array { return ['Rejected on', 'Code', 'Library', 'Amount', 'Reference', 'Reason', 'Rejected by']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return SubscriptionPayment::query()->with(['library', 'reviewer'])->where('status', SubscriptionPayment::REJECTED)
            ->whereDate('reviewed_at', '>=', $from)->whereDate('reviewed_at', '<=', $until)->orderByDesc('reviewed_at')->orderBy('id');
    }

    public function row(Model $m): array
    {
        return [$this->date($m->reviewed_at), $m->library->code, $m->library->name, $this->money($m->amount), $m->reference, $m->review_note, $m->reviewer?->name];
    }

    public function summary(array $f): array { return ['Rejected' => (string) $this->query($f)->count(), 'Amount' => '₹'.$this->money($this->query($f)->sum('amount'))]; }
}
