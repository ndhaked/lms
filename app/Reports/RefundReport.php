<?php

namespace App\Reports;

use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RefundReport extends Report
{
    public function key(): string { return 'refunds'; }
    public function title(): string { return 'Refunds'; }
    public function description(): string { return 'Money paid back to students.'; }
    public function permission(): string { return 'reports.financial'; }

    public function headers(): array { return ['Refund', 'Date', 'Receipt', 'Student', 'Plan', 'Amount', 'Method', 'Reason', 'Recorded by']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return Refund::query()->with(['payment.student', 'membership.plan', 'recorder'])
            ->whereBetween('refunded_on', [$from, $until])->orderByDesc('refunded_on')->orderByDesc('id');
    }

    public function row(Model $m): array
    {
        return [$m->refund_no, $this->date($m->refunded_on), $m->payment->receipt_no, $m->payment->student->name, $m->membership->plan->name,
            $this->money($m->amount), Payment::METHODS[$m->method] ?? $m->method, $m->reason, $m->recorder?->name];
    }

    public function summary(array $f): array
    {
        return ['Refunded' => '₹'.$this->money($this->query($f)->sum('amount')), 'Refunds' => (string) $this->query($f)->count()];
    }
}
