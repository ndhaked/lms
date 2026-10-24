<?php

namespace App\Reports;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Seat;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AttendanceReport extends Report
{
    public function key(): string { return 'attendance'; }
    public function title(): string { return 'Attendance'; }
    public function description(): string { return 'Check-ins and check-outs in the period.'; }
    public function statusOptions(): ?array { return ['inside' => 'Still inside', 'completed' => 'Completed', 'late' => 'Late arrivals']; }

    public function headers(): array { return ['Student ID', 'Student', 'Seat', 'Check-in', 'Check-out', 'Duration', 'Late', 'Recorded by']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return Attendance::query()->with(['student', 'seat', 'checkedInBy'])
            ->whereDate('checked_in_at', '>=', $from)->whereDate('checked_in_at', '<=', $until)
            ->when(($f['status'] ?? '') === 'inside', fn ($q) => $q->whereNull('checked_out_at'))
            ->when(($f['status'] ?? '') === 'completed', fn ($q) => $q->whereNotNull('checked_out_at'))
            ->when(($f['status'] ?? '') === 'late', fn ($q) => $q->where('is_late', true))
            ->orderByDesc('checked_in_at')->orderBy('id');
    }

    public function row(Model $m): array
    {
        $min = $m->minutes();

        return [$m->student->student_code, $m->student->name, $m->seat?->code, $this->date($m->checked_in_at, 'd M Y H:i'),
            $m->checked_out_at ? $this->date($m->checked_out_at, 'd M Y H:i') : 'Inside',
            $min !== null ? intdiv($min, 60).'h '.($min % 60).'m' : '', $m->is_late ? 'Yes' : '', $m->checkedInBy?->name];
    }
}
