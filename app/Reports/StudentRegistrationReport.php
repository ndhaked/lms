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

class StudentRegistrationReport extends Report
{
    public function key(): string { return 'student-registration'; }
    public function title(): string { return 'Student registration'; }
    public function description(): string { return 'Students registered in a period.'; }
    public function exportPermission(): string { return 'students.export'; }

    public function statusOptions(): ?array { return ['active' => 'Active', 'suspended' => 'Suspended', 'inactive' => 'Inactive']; }

    public function headers(): array { return ['Student ID', 'Name', 'Phone', 'Email', 'Registered on', 'Status']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return Student::query()->whereBetween('registered_on', [$from, $until])
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('registered_on')->orderBy('id');
    }

    public function row(Model $m): array
    {
        return [$m->student_code, $m->name, $m->phone, $m->email, $this->date($m->registered_on), ucfirst($m->status)];
    }

    public function summary(array $f): array { return ['Students' => (string) $this->query($f)->count()]; }
}
