<?php

namespace App\Reports\Platform;

use App\Models\Library;
use App\Reports\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LibraryStatusReport extends Report
{
    public function key(): string { return 'library-status'; }
    public function title(): string { return 'Active and inactive libraries'; }
    public function description(): string { return 'Every library with its account status and size.'; }
    public function permission(): string { return 'reports.view'; }
    public function usesRange(): bool { return false; }
    public function statusOptions(): ?array { return Library::STATUSES; }

    public function headers(): array { return ['Code', 'Library', 'Owner', 'Status', 'Students', 'Seats', 'Registered']; }

    public function query(array $f): ?Builder
    {
        return Library::query()->with('owner')->withCount(['students', 'seats'])
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))->orderBy('name')->orderBy('id');
    }

    public function row(Model $m): array
    {
        return [$m->code, $m->name, $m->owner?->name, Library::STATUSES[$m->status] ?? $m->status, $m->students_count, $m->seats_count, $this->date($m->created_at)];
    }

    public function summary(array $f): array
    {
        $c = Library::query()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');

        return collect(Library::STATUSES)->mapWithKeys(fn ($l, $k) => [$l => (string) ($c[$k] ?? 0)])->all();
    }
}
