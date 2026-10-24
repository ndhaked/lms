<?php

namespace App\Support;

use App\Models\Student;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * A library's academic year is a 12-month date range that starts on the 1st of a month chosen in Settings
 * (April by default). A year is identified by the calendar year it STARTS in: 2026 means Apr 2026 – Mar 2027.
 */
class AcademicYear
{
    public static function startMonth(): int
    {
        return min(12, max(1, (int) LibrarySettings::get('academic_year_start_month')));
    }

    /** The year (by start year) that contains the given day, today by default. */
    public static function current(?CarbonInterface $on = null): int
    {
        $on ??= today();

        return $on->month >= self::startMonth() ? $on->year : $on->year - 1;
    }

    /** [first day, last day] of a year. */
    public static function range(int $startYear): array
    {
        $from = Carbon::create($startYear, self::startMonth(), 1)->startOfDay();

        return [$from, $from->copy()->addYear()->subDay()->endOfDay()];
    }

    public static function label(int $startYear): string
    {
        return self::startMonth() === 1 ? (string) $startYear : $startYear.' - '.substr((string) ($startYear + 1), -2);
    }

    /**
     * Years to choose from, newest first: from the year of the library's first record up to the current one
     * (at least the current and the previous year, so there is always something to compare with).
     *
     * @return array<int, string> start year => label
     */
    public static function options(): array
    {
        $current = self::current();
        $first = collect([
            Tenancy::library()?->created_at,
            Student::min('registered_on') ? Carbon::parse(Student::min('registered_on')) : null,
        ])->filter()->map(fn ($d) => self::current($d))->min() ?? $current;

        $first = min($first, $current - 1);

        return collect(range($current, $first))->mapWithKeys(fn ($y) => [$y => self::label($y)])->all();
    }

    /** A requested year if it is one of the options, otherwise the current year. */
    public static function resolve(string|int|null $requested): int
    {
        $requested = (int) $requested;

        return array_key_exists($requested, self::options()) ? $requested : self::current();
    }
}
