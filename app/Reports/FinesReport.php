<?php

namespace App\Reports;

use App\Models\Fine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FinesReport extends Report
{
    public function key(): string { return 'book-fines'; }
    public function title(): string { return 'Book fines'; }
    public function description(): string { return 'Fines raised in the period: unpaid, collected and waived.'; }
    public function module(): ?string { return 'books'; }
    public function permission(): string { return 'reports.financial'; }

    public function statusOptions(): ?array { return ['pending' => 'Unpaid', 'paid' => 'Paid', 'waived' => 'Waived']; }

    public function headers(): array { return ['Date', 'Member ID', 'Member', 'Type', 'Note', 'Amount', 'Waived', 'Paid', 'Left to pay', 'Status', 'Receipt no.', 'Paid on']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return Fine::with('student')->whereBetween('created_at', [$from.' 00:00:00', $until.' 23:59:59'])
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))->orderByDesc('id');
    }

    public function row(Model $m): array
    {
        return [$this->date($m->created_at), $m->student->student_code, $m->student->name, Fine::TYPES[$m->type] ?? $m->type, $m->note, $this->money($m->amount),
            $this->money($m->waived_amount), $this->money($m->paid_amount), $this->money($m->outstanding()), $m->status === 'pending' ? 'Unpaid' : ucfirst($m->status), $m->receipt_no, $this->date($m->paid_on)];
    }

    public function summary(array $f): array
    {
        $rows = $this->query($f)->get();

        return ['Raised' => '₹'.$this->money($rows->sum('amount')), 'Collected' => '₹'.$this->money($rows->sum('paid_amount')), 'Waived' => '₹'.$this->money($rows->sum('waived_amount')),
            'Still unpaid' => '₹'.$this->money($rows->where('status', 'pending')->sum(fn ($x) => (float) $x->outstanding()))];
    }
}
