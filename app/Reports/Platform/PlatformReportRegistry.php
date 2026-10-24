<?php

namespace App\Reports\Platform;

use App\Models\User;
use App\Reports\Report;
use Illuminate\Support\Collection;

class PlatformReportRegistry
{
    /** @return Collection<string, Report> */
    public static function all(): Collection
    {
        return collect([
            new LibraryRegistrationReport, new LibraryStatusReport, new SubscriptionStatusReport, new SubscriptionExpiryReport,
            new SubscriptionRevenueReport, new PendingPaymentReport, new RejectedPaymentReport, new PlanUsageReport,
            new LibraryGrowthReport, new PlatformActivityReport,
        ])->keyBy(fn (Report $r) => $r->key());
    }

    public static function visibleTo(?User $user): Collection
    {
        return static::all()->filter(fn (Report $r) => $user?->hasPlatformPermission($r->permission()) ?? false);
    }

    public static function find(string $key): ?Report
    {
        return static::all()->get($key);
    }
}
