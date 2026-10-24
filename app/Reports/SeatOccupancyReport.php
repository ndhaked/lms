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

class SeatOccupancyReport extends Report
{
    public function key(): string { return 'seat-occupancy'; }
    public function title(): string { return 'Seat occupancy'; }
    public function description(): string { return 'Every seat with its status and current student.'; }
    public function usesRange(): bool { return false; }
    public function statusOptions(): ?array { return Seat::STATUSES; }

    public function headers(): array { return ['Seat', 'Hall / floor', 'Type', 'Status', 'Student', 'Since']; }

    public function query(array $f): ?Builder
    {
        return Seat::query()->with(['hall', 'activeAllocation.student'])
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->orderBy('study_hall_id')->orderBy('code')->orderBy('id');
    }

    public function row(Model $m): array
    {
        $a = $m->activeAllocation;

        return [$m->code, $m->hall?->name, Seat::TYPES[$m->type] ?? $m->type, Seat::STATUSES[$m->status] ?? $m->status, $a?->student?->name, $a ? $this->date($a->allocated_at) : ''];
    }

    public function summary(array $f): array
    {
        $c = Seat::query()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');

        return collect(Seat::STATUSES)->mapWithKeys(fn ($l, $k) => [$l => (string) ($c[$k] ?? 0)])->all();
    }
}
