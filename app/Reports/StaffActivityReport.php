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

class StaffActivityReport extends Report
{
    public function key(): string { return 'staff-activity'; }
    public function title(): string { return 'Staff activity'; }
    public function description(): string { return 'What each team member did, from the audit trail.'; }
    public function statusLabel(): string { return 'Staff member'; }

    public function statusOptions(): ?array
    {
        $library = Library::find(Tenancy::currentId());

        return $library ? $library->users()->orderBy('users.name')->pluck('users.name', 'users.id')->all() : [];
    }

    public function headers(): array { return ['When', 'Staff member', 'Action', 'Record', 'Details']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return AuditLog::query()->with('actor')->where('library_id', Tenancy::currentId())
            ->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $until)
            ->when(! empty($f['status']), fn ($q) => $q->where('user_id', $f['status']))
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    public function row(Model $m): array
    {
        $details = collect($m->properties ?? [])->filter(fn ($v) => filled($v) && is_scalar($v))->map(fn ($v, $k) => "$k: $v")->implode(' · ');

        return [$this->date($m->created_at, 'd M Y H:i'), $m->actor?->name ?? 'System', $m->action,
            $m->auditable_type ? class_basename($m->auditable_type).' #'.$m->auditable_id : '', $details];
    }
}
