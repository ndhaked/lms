<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ReadOnlyLibraryException;
use App\Models\Library;
use App\Models\Seat;
use App\Models\Student;
use App\Models\Subscription;

/**
 * What the active library's subscription allows. A library with no subscription at all is treated as
 * unrestricted (platform-managed), so nothing is blocked until a plan is assigned.
 */
class Entitlements
{
    /** @var array<int, Subscription|null> */
    protected static array $cache = [];

    /** @var array<int, string> */
    protected static array $policies = [];

    protected static array $grants = [];

    public static function subscription(?int $libraryId = null): ?Subscription
    {
        $id = $libraryId ?? Tenancy::currentId();
        if ($id === null) {
            return null;
        }

        return self::$cache[$id] ??= Subscription::currentFor($id);
    }

    public static function flush(): void
    {
        self::$cache = [];
        self::$policies = [];
        self::$grants = [];
    }

    /** none (no plan) | full | warn (trial/grace ending) | restricted (billing page only) */
    public static function state(?int $libraryId = null): string
    {
        $s = self::subscription($libraryId);

        if ($s) {
            $state = $s->accessState();

            return $state === 'restricted' && self::readOnly($libraryId) ? 'readonly' : $state;
        }

        if (! config('billing.require_subscription')) {
            return 'none';
        }

        return self::readOnly($libraryId) ? 'readonly' : 'restricted';
    }

    /** What happens when a library has no usable plan: 'billing_only' (locked) or 'read_only'. Per-library override, else the platform default. */
    public static function policy(?int $libraryId = null): string
    {
        $id = $libraryId ?? Tenancy::currentId();
        $override = $id ? (self::$policies[$id] ??= (string) (\App\Models\Library::whereKey($id)->value('expiry_policy') ?? '')) : '';

        return in_array($override, ['billing_only', 'read_only'], true) ? $override : (string) BillingSettings::get('expiry_policy');
    }

    /**
     * Read-only mode: the library can look at everything but change nothing. It applies when the library has no plan
     * at all or its plan (and grace period) has expired, and only when its policy is "read_only".
     * Suspended, cancelled and never-paid subscriptions are always fully locked.
     */
    public static function readOnly(?int $libraryId = null): bool
    {
        $id = $libraryId ?? Tenancy::currentId();
        if (! $id || self::policy($id) !== 'read_only') {
            return false;
        }

        $s = self::subscription($id);

        return $s ? $s->effectiveStatus() === Subscription::EXPIRED : (bool) config('billing.require_subscription');
    }

    public static function assertWritable(): void
    {
        if (self::readOnly()) {
            throw new ReadOnlyLibraryException();
        }
    }

    public static function allows(): bool
    {
        return self::state() !== 'restricted';
    }

    /** Is a module usable? Included in the plan, or given to this library separately (paid add-on or free). */
    public static function hasModule(string $module, ?int $libraryId = null): bool
    {
        $s = self::subscription($libraryId);

        return ! $s || $s->hasModule($module) || self::grant($module, $libraryId) !== null;
    }

    /** plan | addon | complimentary | null: where a module comes from for this library. */
    public static function moduleSource(string $module, ?int $libraryId = null): ?string
    {
        $s = self::subscription($libraryId);
        if ($s && $s->hasModule($module)) {
            return 'plan';
        }

        return self::grant($module, $libraryId)?->type;
    }

    public static function grant(string $module, ?int $libraryId = null): ?\App\Models\LibraryModule
    {
        $id = $libraryId ?? Tenancy::currentId();
        if ($id === null) {
            return null;
        }

        $grants = self::$grants[$id] ??= \App\Models\LibraryModule::where('library_id', $id)->where('status', 'active')->get()->keyBy('module');
        $row = $grants[$module] ?? null;

        return $row && $row->isActive() ? $row : null;
    }

    /** Number of a resource the library currently has (what counts against the plan limit). */
    public static function usage(string $resource, ?int $libraryId = null): int
    {
        $id = $libraryId ?? Tenancy::currentId();

        return match ($resource) {
            'students' => Student::withoutGlobalScopes()->where('library_id', $id)->where('status', '!=', Student::INACTIVE)->count(),
            'seats' => Seat::withoutGlobalScopes()->where('library_id', $id)->count(),
            'staff' => Library::find($id)?->staffCount() ?? 0,
            default => 0,
        };
    }

    /** Refuse an addition that would push the library past its plan limit. */
    public static function assertCanAdd(string $resource, int $adding = 1): void
    {
        $s = self::subscription();
        $limit = $s?->limit($resource);

        if ($limit === null) {
            return;
        }

        if (self::usage($resource) + $adding > $limit) {
            $noun = ['students' => 'students', 'seats' => 'seats', 'staff' => 'staff accounts'][$resource] ?? $resource;

            throw new BusinessRuleException("Your {$s->plan_name} plan allows up to {$limit} {$noun}. Upgrade your plan to add more.");
        }
    }
}
