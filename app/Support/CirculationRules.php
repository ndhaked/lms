<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The library's lending rules. Nothing here is hard-coded policy: every number comes from the library's settings,
 * per member type (student / staff / faculty), with sensible defaults.
 */
class CirculationRules
{
    public const MEMBER_TYPES = ['student' => 'Student', 'staff' => 'Staff', 'faculty' => 'Faculty'];

    /** Used for any value a library has not set. */
    public const BASE_RULE = ['loan_days' => 14, 'max_items' => 3, 'max_renewals' => 2, 'renewal_days' => 14, 'fine_per_day' => '2.00', 'grace_days' => 0, 'fine_cap' => null, 'max_reservations' => 2];

    /** Starting points when a library has not set anything for that member type. */
    public const TYPE_DEFAULTS = [
        'student' => ['loan_days' => 14, 'max_items' => 3, 'max_renewals' => 2, 'renewal_days' => 14],
        'staff' => ['loan_days' => 21, 'max_items' => 5, 'max_renewals' => 2, 'renewal_days' => 21],
        'faculty' => ['loan_days' => 30, 'max_items' => 10, 'max_renewals' => 3, 'renewal_days' => 30],
    ];

    /**
     * The member types this library uses (key => label). Libraries can add their own in Settings; "student" always exists.
     *
     * @return array<string, string>
     */
    public static function memberTypes(?int $libraryId = null): array
    {
        $types = [];
        foreach ((array) LibrarySettings::get('member_types', [], $libraryId) as $key => $label) {
            if (is_string($key) && preg_match('/^[a-z0-9_]{1,12}$/', $key) && is_string($label) && trim($label) !== '') {
                $types[$key] = mb_substr(trim($label), 0, 30);
            }
        }

        if (! $types) {
            return self::MEMBER_TYPES;
        }

        return isset($types['student']) ? $types : ['student' => self::MEMBER_TYPES['student']] + $types;
    }

    /** @return array{loan_days: int, max_items: int, max_renewals: int, renewal_days: int, fine_per_day: string, grace_days: int, fine_cap: ?string} */
    public static function rule(?string $memberType): array
    {
        $type = array_key_exists((string) $memberType, self::memberTypes()) ? $memberType : 'student';
        $saved = (array) (LibrarySettings::get('loan_rules')[$type] ?? []);
        $rule = array_replace(self::BASE_RULE, self::TYPE_DEFAULTS[$type] ?? self::TYPE_DEFAULTS['student'], array_filter($saved, fn ($v) => $v !== null && $v !== ''));

        return [
            'loan_days' => max(1, (int) $rule['loan_days']),
            'max_items' => max(1, (int) $rule['max_items']),
            'max_renewals' => max(0, (int) $rule['max_renewals']),
            'renewal_days' => max(1, (int) $rule['renewal_days']),
            'fine_per_day' => number_format((float) $rule['fine_per_day'], 2, '.', ''),
            'grace_days' => max(0, (int) $rule['grace_days']),
            'max_reservations' => max(0, (int) $rule['max_reservations']),
            'fine_cap' => $rule['fine_cap'] !== null && $rule['fine_cap'] !== '' ? number_format((float) $rule['fine_cap'], 2, '.', '') : null,
        ];
    }

    /** Due date = from + days, moved forward past closed days and holidays so it never falls when the library is shut. */
    public static function dueDate(CarbonInterface $from, int $days): Carbon
    {
        $due = Carbon::parse($from->toDateString())->addDays($days);

        for ($i = 0; $i < 31 && ! LibrarySettings::isWorkingDay($due); $i++) {
            $due->addDay();
        }

        return $due;
    }

    /** Days late as of $on (grace subtracted; closed days not counted if the library says so). */
    public static function overdueDays(CarbonInterface $due, CarbonInterface $on, int $graceDays = 0): int
    {
        $dueDay = Carbon::parse($due->toDateString());
        $onDay = Carbon::parse($on->toDateString());
        if ($onDay->lte($dueDay)) {
            return 0;
        }

        $skipClosed = (bool) LibrarySettings::get('fine_skip_closed_days');
        $days = 0;
        for ($d = $dueDay->copy()->addDay(), $guard = 0; $d->lte($onDay) && $guard < 3650; $d->addDay(), $guard++) {
            if (! $skipClosed || LibrarySettings::isWorkingDay($d)) {
                $days++;
            }
        }

        return max(0, $days - $graceDays);
    }

    public static function fineFor(array $rule, int $days): string
    {
        $fine = bcmul($rule['fine_per_day'], (string) $days, 2);
        if ($rule['fine_cap'] !== null && bccomp($fine, $rule['fine_cap'], 2) > 0) {
            $fine = $rule['fine_cap'];
        }

        return $fine;
    }
}
