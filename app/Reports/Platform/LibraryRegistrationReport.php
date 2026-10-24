<?php

namespace App\Reports\Platform;

use App\Models\Library;
use App\Reports\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LibraryRegistrationReport extends Report
{
    public function key(): string { return 'library-registrations'; }
    public function title(): string { return 'Library registrations'; }
    public function description(): string { return 'Libraries registered in the period.'; }
    public function permission(): string { return 'reports.view'; }
    public function statusOptions(): ?array { return Library::STATUSES; }

    public function headers(): array { return ['Code', 'Library', 'Owner', 'Owner email', 'Owner phone', 'Status', 'Registered', 'Activated']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return Library::query()->with('owner')->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $until)
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))->orderByDesc('created_at')->orderBy('id');
    }

    public function row(Model $m): array
    {
        return [$m->code, $m->name, $m->owner?->name, $m->owner?->email, $m->owner?->phone, Library::STATUSES[$m->status] ?? $m->status,
            $this->date($m->created_at), $m->activated_at ? $this->date($m->activated_at) : ''];
    }

    public function summary(array $f): array { return ['Libraries' => (string) $this->query($f)->count()]; }
}
