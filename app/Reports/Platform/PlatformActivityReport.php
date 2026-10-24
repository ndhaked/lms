<?php

namespace App\Reports\Platform;

use App\Models\AuditLog;
use App\Reports\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PlatformActivityReport extends Report
{
    public function key(): string { return 'platform-activity'; }
    public function title(): string { return 'Platform activity'; }
    public function description(): string { return 'Administrative actions across all libraries, from the audit trail.'; }
    public function permission(): string { return 'reports.view'; }

    public function headers(): array { return ['When', 'Actor', 'Action', 'Library', 'Record', 'Details']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return AuditLog::query()->with(['actor', 'library'])->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $until)
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    public function row(Model $m): array
    {
        $details = collect($m->properties ?? [])->filter(fn ($v) => filled($v) && is_scalar($v))->map(fn ($v, $k) => "$k: $v")->implode(' · ');

        return [$this->date($m->created_at, 'd M Y H:i'), $m->actor?->name ?? 'System', $m->action, $m->library?->name ?? 'Platform',
            $m->auditable_type ? class_basename($m->auditable_type).' #'.$m->auditable_id : '', $details];
    }
}
