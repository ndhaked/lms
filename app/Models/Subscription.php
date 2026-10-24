<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A library's subscription. The STORED status is what the platform staff set; effectiveStatus()
 * and accessState() add the passing of time (grace period, expiry). Payment status lives on
 * SubscriptionPayment and is deliberately kept separate.
 */
class Subscription extends Model
{
    public const TRIAL = 'trial';
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const EXPIRED = 'expired';
    public const SUSPENDED = 'suspended';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::TRIAL => 'Trial', self::PENDING => 'Pending', self::ACTIVE => 'Active',
        self::EXPIRED => 'Expired', self::SUSPENDED => 'Suspended', self::CANCELLED => 'Cancelled',
    ];

    public const CYCLE_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'annual' => 12];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2', 'modules' => 'array',
            'is_complimentary' => 'boolean', 'starts_on' => 'date', 'trial_ends_on' => 'date', 'expires_on' => 'date', 'cancelled_at' => 'datetime',
        ];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function nextPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'next_plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class)->latest('created_at')->latest('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /** The library's current subscription (its latest row), if it has one. */
    public static function currentFor(int $libraryId): ?self
    {
        return static::where('library_id', $libraryId)->latest('id')->first();
    }

    /** Last day covered, inclusive: a monthly period starting 1 Jan ends 31 Jan. */
    public static function periodEnd(CarbonInterface $start, string $cycle): CarbonInterface
    {
        $months = self::CYCLE_MONTHS[$cycle] ?? throw new BusinessRuleException('Unknown billing cycle.');

        return $start->copy()->startOfDay()->addMonthsNoOverflow($months)->subDay();
    }

    /** Last day the library is entitled to (end of trial or paid period). */
    public function coveredUntil(): ?CarbonInterface
    {
        return $this->status === self::TRIAL ? $this->trial_ends_on : $this->expires_on;
    }

    public function graceEndsOn(): ?CarbonInterface
    {
        return $this->coveredUntil()?->copy()->addDays((int) $this->grace_days);
    }

    /** trial | active | grace | expired | suspended | cancelled | pending, taking dates into account. */
    public function effectiveStatus(?CarbonInterface $on = null): string
    {
        $on ??= today();

        if (in_array($this->status, [self::SUSPENDED, self::CANCELLED, self::PENDING], true)) {
            return $this->status;
        }

        $until = $this->coveredUntil();
        if (! $until || $until->gte($on)) {
            return $this->status === self::EXPIRED ? self::EXPIRED : $this->status; // trial or active, still covered
        }

        return $this->graceEndsOn()->gte($on) ? 'grace' : self::EXPIRED;
    }

    /** full: normal use. warn: usable but ending (trial / grace). restricted: billing page only. */
    public function accessState(?CarbonInterface $on = null): string
    {
        return match ($this->effectiveStatus($on)) {
            self::ACTIVE, self::TRIAL => 'full',
            'grace' => 'warn',
            default => 'restricted',
        };
    }

    public function daysLeft(): ?int
    {
        $until = $this->coveredUntil();

        return $until ? (int) today()->diffInDays($until, false) : null;
    }

    public function limit(string $resource): ?int
    {
        return match ($resource) {
            'students' => $this->max_students,
            'seats' => $this->max_seats,
            'staff' => $this->max_staff,
            default => null,
        };
    }

    public function hasModule(string $module): bool
    {
        return ! array_key_exists($module, SubscriptionPlan::MODULES) || in_array($module, (array) $this->modules, true);
    }

    /** Copy the plan's current limits, price and modules onto this subscription. */
    public function applyPlan(SubscriptionPlan $plan, ?string $cycle = null): void
    {
        $cycle ??= $this->billing_cycle;

        $this->fill([
            'subscription_plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'billing_cycle' => $cycle,
            'price' => $plan->priceFor($cycle) ?? 0,
            'max_students' => $plan->max_students,
            'max_seats' => $plan->max_seats,
            'max_staff' => $plan->max_staff,
            'modules' => $plan->modules ?? [],
            'grace_days' => $plan->grace_days,
        ]);
    }
}
