<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    public const CYCLES = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annual' => 'Annual'];

    /** Optional modules a plan can switch on or off. The core (students, seats, memberships, payments) is always on. */
    public const MODULES = [
        'attendance' => 'Attendance',
        'expenses' => 'Expenses',
        'reports' => 'Reports',
        'exports' => 'Data exports',
        'books' => 'Books & circulation',
        'ebooks' => 'E-books',
    ];

    protected $attributes = ['status' => 'active', 'grace_days' => 7, 'trial_days' => 0];

    protected $fillable = [
        'name', 'description', 'price_monthly', 'price_quarterly', 'price_annual', 'trial_days',
        'max_students', 'max_seats', 'max_staff', 'modules', 'grace_days', 'status',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2', 'price_quarterly' => 'decimal:2', 'price_annual' => 'decimal:2',
            'modules' => 'array',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Price for a billing cycle, or null when the plan is not sold on that cycle. */
    public function priceFor(string $cycle): ?string
    {
        $value = match ($cycle) {
            'monthly' => $this->price_monthly,
            'quarterly' => $this->price_quarterly,
            'annual' => $this->price_annual,
            default => null,
        };

        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }

    /** @return list<string> the cycles this plan is sold on */
    public function cycles(): array
    {
        return array_values(array_filter(array_keys(self::CYCLES), fn ($c) => $this->priceFor($c) !== null));
    }
}
