<?php

namespace App\Reports;

use App\Models\LoanItem;
use App\Support\CirculationRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Books that are out past their due date right now (the period filter does not apply). */
class OverdueBooksReport extends Report
{
    public function key(): string { return 'overdue-books'; }
    public function title(): string { return 'Overdue books'; }
    public function description(): string { return 'Books out past their due date today, with the fine so far.'; }
    public function module(): ?string { return 'books'; }
    public function permission(): string { return 'reports.operational'; }
    public function usesRange(): bool { return false; }

    public function headers(): array { return ['Member ID', 'Member', 'Phone', 'Book', 'Accession no.', 'Due on', 'Days late', 'Fine so far']; }

    public function query(array $f): ?Builder
    {
        return LoanItem::with(['student', 'book', 'copy'])->overdue()->orderBy('due_on')->orderBy('id');
    }

    public function row(Model $m): array
    {
        $rule = CirculationRules::rule($m->student->member_type);
        $late = CirculationRules::overdueDays($m->due_on, now(), $rule['grace_days']);

        return [$m->student->student_code, $m->student->name, $m->student->phone, $m->book->title, $m->copy->accession_no, $this->date($m->due_on), $late, $this->money(CirculationRules::fineFor($rule, $late))];
    }

    public function summary(array $f): array { return ['Overdue books' => (string) $this->query($f)->count()]; }
}
