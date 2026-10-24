<?php

namespace App\Reports;

use App\Models\LoanItem;
use Illuminate\Support\Collection;

/** Which titles are borrowed most in the period. */
class MostIssuedBooksReport extends Report
{
    public function key(): string { return 'most-issued-books'; }
    public function title(): string { return 'Most issued books'; }
    public function description(): string { return 'Titles ranked by how often they were issued in the period.'; }
    public function module(): ?string { return 'books'; }
    public function permission(): string { return 'reports.operational'; }

    public function headers(): array { return ['Rank', 'Title', 'Author', 'ISBN', 'Times issued', 'Different members']; }

    public function collection(array $f): Collection
    {
        [$from, $until] = $this->range($f);

        return LoanItem::query()->whereBetween('issued_at', [$from.' 00:00:00', $until.' 23:59:59'])->whereIn('status', ['issued', 'returned', 'lost'])
            ->selectRaw('book_id, count(*) as times, count(distinct student_id) as members')->groupBy('book_id')->orderByDesc('times')->orderBy('book_id')->limit(500)->get()
            ->map(function ($r, $i) {
                $b = \App\Models\Book::find($r->book_id);

                return [$i + 1, $b?->title, $b?->author, $b?->isbn, (int) $r->times, (int) $r->members];
            })->values();
    }
}
