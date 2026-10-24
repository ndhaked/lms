<?php

namespace App\Reports\Platform;

use App\Models\Library;
use App\Reports\Report;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class LibraryGrowthReport extends Report
{
    public function key(): string { return 'library-growth'; }
    public function title(): string { return 'Library growth'; }
    public function description(): string { return 'New libraries per month and the running total.'; }
    public function permission(): string { return 'reports.view'; }

    public function defaultFilters(): array
    {
        return ['from' => now()->startOfMonth()->subMonths(11)->toDateString(), 'until' => today()->toDateString(), 'status' => ''];
    }

    public function headers(): array { return ['Month', 'New libraries', 'Total libraries']; }

    public function collection(array $f): Collection
    {
        [$from, $until] = $this->range($f);
        $start = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($until)->endOfMonth();

        $perMonth = Library::pluck('created_at')->countBy(fn ($d) => $d->format('Y-m'));
        $running = Library::where('created_at', '<', $start)->count();

        $rows = collect();
        for ($m = $start->copy(); $m->lte($end); $m->addMonthNoOverflow()) {
            $new = $perMonth[$m->format('Y-m')] ?? 0;
            $running += $new;
            $rows->push([$m->format('M Y'), $new, $running]);
        }

        return $rows;
    }
}
