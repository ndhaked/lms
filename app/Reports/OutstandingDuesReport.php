<?php

namespace App\Reports;

use App\Models\StudentMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OutstandingDuesReport extends Report
{
    public function key(): string { return 'outstanding-dues'; }
    public function title(): string { return 'Outstanding dues'; }
    public function description(): string { return 'Memberships that are not fully paid, as of today.'; }
    public function permission(): string { return 'reports.financial'; }
    public function usesRange(): bool { return false; }
    public function statusOptions(): ?array { return ['overdue' => 'Overdue (membership ended)', 'current' => 'Current (still running)']; }

    public function headers(): array { return ['Student ID', 'Student', 'Phone', 'Plan', 'Period', 'Charge', 'Paid', 'Balance', 'Overdue']; }

    public function query(array $f): ?Builder
    {
        return StudentMembership::owing()->withBalance()->with(['student', 'plan'])
            ->when(($f['status'] ?? '') === 'overdue', fn ($q) => $q->whereDate('student_memberships.ends_on', '<', today()))
            ->when(($f['status'] ?? '') === 'current', fn ($q) => $q->whereDate('student_memberships.ends_on', '>=', today()))
            ->orderBy('student_memberships.ends_on')->orderBy('student_memberships.id');
    }

    public function row(Model $m): array
    {
        return [$m->student->student_code, $m->student->name, $m->student->phone, $m->plan->name,
            $this->date($m->starts_on).' – '.$this->date($m->ends_on), $this->money($m->charge), $this->money($m->paid), $this->money($m->balance),
            $m->ends_on->lt(today()) ? 'Yes' : ''];
    }

    public function summary(array $f): array
    {
        return ['Total outstanding' => '₹'.$this->money($this->query($f)->get()->sum('balance'))];
    }
}
