<?php

namespace App\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A report is read-only: it returns rows for the ACTIVE library only (every model used here carries the
 * tenant scope) and is only offered to users holding its permission.
 *
 * Query-based reports implement query() + row(); summary-style reports override collection().
 */
abstract class Report
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** @return list<string> column headings */
    abstract public function headers(): array;

    /** The plan module this report belongs to (null = always available). */
    public function module(): ?string
    {
        return null;
    }

    public function permission(): string
    {
        return 'reports.operational';
    }

    public function exportPermission(): string
    {
        return $this->permission();
    }

    public function usesRange(): bool
    {
        return true;
    }

    /** @return array<string, string>|null options for the extra filter, or null for none */
    public function statusOptions(): ?array
    {
        return null;
    }

    public function statusLabel(): string
    {
        return 'Status';
    }

    public function defaultFilters(): array
    {
        return ['from' => now()->startOfMonth()->toDateString(), 'until' => today()->toDateString(), 'status' => ''];
    }

    /** Ordered query (ordering is required so the export can stream in chunks). */
    public function query(array $f): ?Builder
    {
        return null;
    }

    /** @return list<scalar|null> one value per header */
    public function row(Model $model): array
    {
        return [];
    }

    /** For reports that are not a plain list of records. @return Collection<int, list<scalar|null>> */
    public function collection(array $f): Collection
    {
        return collect();
    }

    /** @return array<string, string> label => value shown above the table */
    public function summary(array $f): array
    {
        return [];
    }

    public function isQuery(): bool
    {
        return $this->query($this->defaultFilters()) !== null;
    }

    protected function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    protected function date(mixed $value, string $format = 'd M Y'): string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->format($format) : '';
    }

    /** Normalised inclusive range from the filter array. */
    protected function range(array $f): array
    {
        $from = ! empty($f['from']) ? $f['from'] : '1970-01-01';
        $until = ! empty($f['until']) ? $f['until'] : '2999-12-31';

        return [$from, $until];
    }
}
