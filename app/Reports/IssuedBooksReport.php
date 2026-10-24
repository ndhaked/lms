<?php

namespace App\Reports;

use App\Models\LoanItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class IssuedBooksReport extends Report
{
    public function key(): string { return 'issued-books'; }
    public function title(): string { return 'Issued books'; }
    public function description(): string { return 'Every book issued in the period, and where it stands now.'; }
    public function module(): ?string { return 'books'; }
    public function permission(): string { return 'reports.operational'; }
    public function statusLabel(): string { return 'State'; }

    public function statusOptions(): ?array { return ['issued' => 'Still out', 'returned' => 'Returned', 'lost' => 'Lost', 'reversed' => 'Reversed']; }

    public function headers(): array { return ['Loan no.', 'Member ID', 'Member', 'Book', 'ISBN', 'Accession no.', 'Issued on', 'Due on', 'Returned on', 'Renewals', 'State']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return LoanItem::with(['loan', 'student', 'book', 'copy'])->whereBetween('issued_at', [$from.' 00:00:00', $until.' 23:59:59'])
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))->orderByDesc('issued_at')->orderBy('id');
    }

    public function row(Model $m): array
    {
        return [$m->loan->loan_no, $m->student->student_code, $m->student->name, $m->book->title, $m->book->isbn, $m->copy->accession_no,
            $this->date($m->issued_at), $this->date($m->due_on), $this->date($m->returned_at), $m->renewals, ucfirst($m->status)];
    }

    public function summary(array $f): array
    {
        $q = $this->query($f);

        return ['Books issued' => (string) $q->count(), 'Still out' => (string) (clone $q)->where('status', 'issued')->count()];
    }
}
