<?php

namespace App\Reports;

use App\Models\Book;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The whole catalogue as one sheet: titles, e-books, and how many copies each has. */
class BookCatalogueReport extends Report
{
    public function key(): string
    {
        return 'book-catalogue';
    }

    public function title(): string
    {
        return 'Book catalogue';
    }

    public function description(): string
    {
        return 'Every title with its copies.';
    }

    public function permission(): string
    {
        return 'books.view';
    }

    public function module(): ?string
    {
        return 'books';
    }

    public function exportPermission(): string
    {
        return 'books.export';
    }

    public function usesRange(): bool
    {
        return false;
    }

    public function headers(): array
    {
        return ['Type', 'Title', 'Subtitle', 'ISBN', 'Author', 'Publisher', 'Edition', 'Language', 'Year', 'Category', 'Tags', 'Shelf', 'Rack', 'Section', 'Location', 'Copies', 'Available', 'Status', 'Link'];
    }

    public function query(array $f): ?Builder
    {
        return Book::with('category.parent')
            ->withCount(['copies as copies_total' => fn ($q) => $q->inStock(), 'copies as copies_available' => fn ($q) => $q->where('status', 'available')])
            ->orderBy('id');
    }

    public function row(Model $m): array
    {
        return [
            Book::TYPES[$m->type] ?? $m->type, $m->title, $m->subtitle, $m->isbn, $m->author, $m->publisher, $m->edition, $m->language, $m->publication_year,
            $m->category?->fullName(), implode(', ', (array) $m->tags), $m->shelf, $m->rack, $m->section, $m->location,
            $m->isEbook() ? null : $m->copies_total, $m->isEbook() ? null : $m->copies_available, ucfirst($m->status), $m->external_url,
        ];
    }
}
