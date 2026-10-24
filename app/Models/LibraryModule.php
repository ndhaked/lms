<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A module given to ONE library on top of (or instead of) its plan: a paid add-on that is billed with the
 * subscription, or a complimentary one. Managed by platform staff only, so this is not tenant-scoped.
 */
class LibraryModule extends Model
{
    public const ADDON = 'addon';
    public const COMPLIMENTARY = 'complimentary';
    public const TYPES = [self::ADDON => 'Paid add-on', self::COMPLIMENTARY => 'Free'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'revoked_at' => 'datetime'];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    /** Switched on right now (not revoked, not past its free end date). */
    public function isActive(): bool
    {
        return $this->status === 'active' && ($this->expires_on === null || $this->expires_on->gte(today()));
    }

    public function priceFor(string $cycle): string
    {
        $monthly = (float) $this->price_monthly;
        $value = match ($cycle) {
            'quarterly' => $this->price_quarterly ?? $monthly * 3,
            'annual' => $this->price_annual ?? $monthly * 12,
            default => $monthly,
        };

        return number_format((float) $value, 2, '.', '');
    }
}
