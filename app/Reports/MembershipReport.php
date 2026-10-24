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

class MembershipReport extends Report
{
    public function key(): string { return 'membership'; }
    public function title(): string { return 'Memberships'; }
    public function description(): string { return 'Memberships running at any point in the period.'; }

    public function statusOptions(): ?array
    {
        return ['active' => 'Active', 'upcoming' => 'Upcoming', 'expired' => 'Expired', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'];
    }

    public function headers(): array { return ['Student ID', 'Student', 'Plan', 'Starts', 'Ends', 'Status', 'Price', 'Discount']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return StudentMembership::query()->with(['student', 'plan'])
            ->whereDate('starts_on', '<=', $until)->whereDate('ends_on', '>=', $from)
            ->when(($f['status'] ?? '') === 'active', fn ($q) => $q->current())
            ->when(($f['status'] ?? '') === 'upcoming', fn ($q) => $q->where('status', 'active')->whereDate('starts_on', '>', today()))
            ->when(($f['status'] ?? '') === 'expired', fn ($q) => $q->where('status', 'active')->whereDate('ends_on', '<', today()))
            ->when(in_array($f['status'] ?? '', ['suspended', 'cancelled'], true), fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('starts_on')->orderBy('id');
    }

    public function row(Model $m): array
    {
        return [$m->student->student_code, $m->student->name, $m->plan->name, $this->date($m->starts_on), $this->date($m->ends_on),
            ucfirst($m->effectiveStatus()), $this->money($m->price), $this->money($m->discount)];
    }
}
