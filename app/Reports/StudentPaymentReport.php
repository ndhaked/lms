<?php

namespace App\Reports;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StudentPaymentReport extends Report
{
    public function key(): string { return 'student-payments'; }
    public function title(): string { return 'Student payments'; }
    public function description(): string { return 'Fees received from students, with receipt numbers.'; }
    public function permission(): string { return 'reports.financial'; }
    public function statusOptions(): ?array { return ['completed' => 'Completed', 'reversed' => 'Reversed']; }

    public function headers(): array { return ['Receipt', 'Date', 'Student ID', 'Student', 'Amount', 'Method', 'Reference', 'Status', 'Refunded', 'Recorded by']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return Payment::query()->with(['student', 'recorder', 'refunds'])
            ->whereBetween('paid_on', [$from, $until])
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('paid_on')->orderByDesc('id');
    }

    public function row(Model $m): array
    {
        return [$m->receipt_no, $this->date($m->paid_on), $m->student->student_code, $m->student->name, $this->money($m->amount),
            Payment::METHODS[$m->method] ?? $m->method, $m->reference, ucfirst($m->status), $this->money($m->refunds->sum('amount')), $m->recorder?->name];
    }

    public function summary(array $f): array
    {
        $q = $this->query($f);

        return [
            'Received (completed)' => '₹'.$this->money((clone $q)->where('status', Payment::COMPLETED)->sum('amount')),
            'Payments' => (string) (clone $q)->count(),
        ];
    }
}
