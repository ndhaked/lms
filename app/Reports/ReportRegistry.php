<?php

namespace App\Reports;

use App\Models\User;
use Illuminate\Support\Collection;

class ReportRegistry
{
    /** @return Collection<string, Report> keyed by report key */
    public static function all(): Collection
    {
        return collect([
            new StudentRegistrationReport,
            new MembershipReport,
            new MembershipExpiryReport,
            new SeatOccupancyReport,
            new AttendanceReport,
            new StudentPaymentReport,
            new OutstandingDuesReport,
            new RefundReport,
            new ExpenseReport,
            new IncomeExpenseReport,
            new StaffActivityReport,
            new IssuedBooksReport,
            new OverdueBooksReport,
            new FinesReport,
            new BookStockReport,
            new MostIssuedBooksReport,
        ])->keyBy(fn (Report $r) => $r->key());
    }

    /** Only the reports this user holds the permission for (library-level permissions, active library). */
    public static function visibleTo(?User $user): Collection
    {
        return static::all()->filter(fn (Report $r) => ($user?->can($r->permission()) ?? false) && ($r->module() === null || \App\Support\Entitlements::hasModule($r->module())));
    }

    public static function find(string $key): ?Report
    {
        return static::all()->get($key);
    }
}
