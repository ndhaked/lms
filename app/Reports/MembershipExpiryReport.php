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

class MembershipExpiryReport extends Report
{
    public function key(): string { return 'membership-expiry'; }
    public function title(): string { return 'Membership expiry'; }
    public function description(): string { return 'Active memberships ending in the period (default: next 30 days).'; }

    public function defaultFilters(): array
    {
        return ['from' => today()->toDateString(), 'until' => today()->addDays(30)->toDateString(), 'status' => ''];
    }

    public function headers(): array { return ['Student ID', 'Student', 'Phone', 'Plan', 'Ends on', 'Days left']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return StudentMembership::query()->with(['student', 'plan'])->where('status', 'active')
            ->whereBetween('ends_on', [$from, $until])->orderBy('ends_on')->orderBy('id');
    }

    public function row(Model $m): array
    {
        return [$m->student->student_code, $m->student->name, $m->student->phone, $m->plan->name, $this->date($m->ends_on), (int) today()->diffInDays($m->ends_on, false)];
    }
}
