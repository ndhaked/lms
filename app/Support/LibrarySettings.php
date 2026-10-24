<?php

namespace App\Support;

use App\Models\Library;
use Illuminate\Support\Facades\DB;

/**
 * Per-library operational settings, stored as one JSON document per library.
 * Always read through get(); unknown or missing keys fall back to DEFAULTS.
 */
class LibrarySettings
{
    public const DEFAULTS = [
        // hours & days
        'working_days' => [1, 2, 3, 4, 5, 6],        // ISO weekday: 1 = Monday ... 7 = Sunday
        'holidays' => [],                            // list of ['date' => 'Y-m-d', 'label' => string]
        // seats
        'seat_prefix' => 'A-',
        'seat_padding' => 2,
        // attendance
        'attendance_require_membership' => true,
        'attendance_grace_minutes' => 15,            // late arrival = after the plan's shift start + this grace
        // memberships
        'renewal_window_days' => 7,                  // "expiring soon" window and reminder lead time
        'academic_year_start_month' => 4,            // 1-12; the academic year runs 12 months from the 1st of this month
        // lending rules: per member type, any value left out falls back to App\Support\CirculationRules defaults
        'loan_rules' => [],
        'isbn_lookup' => true,                       // fill a book's details from its ISBN using free online catalogues
        'member_types' => [],                        // key => label; empty = the built-in student / staff / faculty
        'loan_no_prefix' => 'LN',
        'reservations_enabled' => true,              // members can queue for a title that is out
        'reservation_hold_days' => 3,                // days a returned copy is held for the member at the front of the queue
        'circulation_require_membership' => true,   // members need a running membership to borrow
        'block_same_title_twice' => true,            // a member cannot hold two copies of the same title
        'allow_renew_overdue' => false,
        'student_can_renew' => true,                 // members can renew from their own page
        'fine_skip_closed_days' => true,             // closed days and holidays do not earn fines
        'block_issue_fines_over' => 0,               // unpaid fines at or above this stop new issues; 0 = never
        'lost_fine_multiplier' => 1,                 // lost book fine = copy price x this + extra
        'lost_fine_extra' => 0,
        'repeat_overdue_alert' => 3,                 // tell staff when a member returns this many books late within 90 days; 0 = off
        'accession_prefix' => 'ACC',                 // accession numbers look like ACC-000001
        'accession_padding' => 6,
        'ebook_require_membership' => true,          // students need a running membership to open e-books
        'student_payment_submissions' => false,      // students may send "I have paid" details for staff to confirm
        'renewal_starts_after_expiry' => true,       // renew continues from the old end date (else from today)
        // receipts & tax
        'receipt_prefix' => 'RC',
        'receipt_header' => '',
        'receipt_footer' => '',
        'tax_number' => '',
        'tax_note' => '',
        // notifications
        'notify_expiry' => true,
        'notify_dues' => true,
        // data
        'export_enabled' => true,
    ];

    /** @var array<int, array<string, mixed>> request-level cache */
    protected static array $cache = [];

    public static function all(?int $libraryId = null): array
    {
        $id = $libraryId ?? Tenancy::currentId();
        if ($id === null) {
            return self::DEFAULTS;
        }

        return self::$cache[$id] ??= array_replace(
            self::DEFAULTS,
            json_decode((string) DB::table('library_settings')->where('library_id', $id)->value('data'), true) ?: [],
        );
    }

    public static function get(string $key, mixed $default = null, ?int $libraryId = null): mixed
    {
        return self::all($libraryId)[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    /** Merge new values over the stored ones (only known keys are kept). */
    public static function update(array $values, ?Library $library = null): void
    {
        Entitlements::assertWritable();

        $id = $library?->getKey() ?? Tenancy::currentId() ?? throw new \LogicException('No active library.');
        $merged = array_replace(self::all($id), array_intersect_key($values, self::DEFAULTS));

        DB::table('library_settings')->updateOrInsert(
            ['library_id' => $id],
            ['data' => json_encode($merged), 'updated_at' => now(), 'created_at' => now()],
        );

        unset(self::$cache[$id]);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    public static function isWorkingDay(\Carbon\CarbonInterface $date, ?int $libraryId = null): bool
    {
        if (collect(self::get('holidays', [], $libraryId))->contains(fn ($h) => ($h['date'] ?? null) === $date->toDateString())) {
            return false;
        }

        return in_array($date->dayOfWeekIso, self::get('working_days', [], $libraryId), true);
    }
}
