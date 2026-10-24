<?php

namespace App\Reports;

use App\Models\BookCopy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Every copy with its status: the stock register, including lost, damaged and withdrawn copies. */
class BookStockReport extends Report
{
    public function key(): string { return 'book-stock'; }
    public function title(): string { return 'Book stock'; }
    public function description(): string { return 'Every copy and its status. Filter to see lost, damaged or withdrawn copies.'; }
    public function module(): ?string { return 'books'; }
    public function permission(): string { return 'reports.operational'; }
    public function usesRange(): bool { return false; }
    public function statusLabel(): string { return 'Copy status'; }

    public function statusOptions(): ?array { return BookCopy::STATUSES; }

    public function headers(): array { return ['Accession no.', 'Barcode', 'Title', 'Author', 'ISBN', 'Status', 'Purchase price', 'Supplier', 'Purchased on', 'Withdrawn on', 'Note']; }

    public function query(array $f): ?Builder
    {
        return BookCopy::with('book')->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))->orderBy('accession_no');
    }

    public function row(Model $m): array
    {
        return [$m->accession_no, $m->barcode, $m->book->title, $m->book->author, $m->book->isbn, BookCopy::STATUSES[$m->status] ?? $m->status,
            $m->purchase_price !== null ? $this->money($m->purchase_price) : '', $m->supplier, $this->date($m->purchased_on), $this->date($m->withdrawn_on), $m->note];
    }

    public function summary(array $f): array
    {
        return collect(BookCopy::STATUSES)->mapWithKeys(fn ($label, $key) => [$label => (string) BookCopy::where('status', $key)->count()])->all();
    }
}
