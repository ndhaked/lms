<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Seat extends Model
{
    use BelongsToLibrary;

    public const AVAILABLE = 'available';
    public const OCCUPIED = 'occupied';
    public const RESERVED = 'reserved';
    public const UNAVAILABLE = 'unavailable';
    public const MAINTENANCE = 'maintenance';

    public const STATUSES = [
        self::AVAILABLE => 'Available',
        self::OCCUPIED => 'Occupied',
        self::RESERVED => 'Reserved',
        self::UNAVAILABLE => 'Unavailable',
        self::MAINTENANCE => 'Maintenance',
    ];

    public const TYPES = ['standard' => 'Standard', 'cabin' => 'Cabin', 'computer' => 'Computer', 'group' => 'Group table'];

    protected $attributes = ['status' => 'available', 'type' => 'standard'];

    protected $fillable = ['study_hall_id', 'code', 'type', 'status', 'notes'];

    public function hall(): BelongsTo
    {
        return $this->belongsTo(StudyHall::class, 'study_hall_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SeatAllocation::class);
    }

    public function activeAllocations(): HasMany
    {
        return $this->hasMany(SeatAllocation::class)->whereNull('released_at');
    }

    /** Who has the seat in this shift (a full-day holder covers every shift; asking for fullday returns anyone). Needs activeAllocations loaded. */
    public function holderFor(string $shift): ?SeatAllocation
    {
        return $this->activeAllocations->first(fn ($a) => $shift === 'fullday' || $a->shift === 'fullday' || $a->shift === $shift);
    }

    /** What the seat looks like in a shift: its status, with occupied/available decided by that shift's holder. */
    public function stateFor(string $shift): string
    {
        if (! in_array($this->status, [self::AVAILABLE, self::OCCUPIED], true)) {
            return $this->status;
        }

        return $this->holderFor($shift) ? self::OCCUPIED : self::AVAILABLE;
    }

    public function isFreeFor(string $shift): bool
    {
        return $this->stateFor($shift) === self::AVAILABLE;
    }

    public function activeAllocation(): HasOne
    {
        return $this->hasOne(SeatAllocation::class)->whereNull('released_at');
    }
}
