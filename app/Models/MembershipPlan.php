<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class MembershipPlan extends Model
{
    use BelongsToLibrary;

    public const DURATIONS = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'half_yearly' => 'Half-yearly',
        'yearly' => 'Yearly',
    ];

    public const SEAT_TYPES = ['fixed' => 'Fixed seat', 'flexible' => 'Flexible seat'];

    protected $attributes = ['status' => 'active', 'seat_type' => 'flexible', 'grace_days' => 0, 'registration_fee' => 0];

    protected $fillable = [
        'name', 'description', 'duration_unit', 'price', 'registration_fee', 'seat_type',
        'shift_start', 'shift_end', 'grace_days', 'status',
    ];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'registration_fee' => 'decimal:2'];
    }

    /** Last day covered, inclusive: a monthly plan starting 1 Jan ends 31 Jan. */
    public function endDateFor(CarbonInterface $start): CarbonInterface
    {
        $start = $start->copy()->startOfDay();

        return match ($this->duration_unit) {
            'daily' => $start,
            'weekly' => $start->addWeek()->subDay(),
            'monthly' => $start->addMonthNoOverflow()->subDay(),
            'quarterly' => $start->addMonthsNoOverflow(3)->subDay(),
            'half_yearly' => $start->addMonthsNoOverflow(6)->subDay(),
            'yearly' => $start->addYearNoOverflow()->subDay(),
        };
    }
}
