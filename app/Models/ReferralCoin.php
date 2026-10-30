<?php

namespace App\Models;

use App\Support\BillingSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line in a library's referral-coin ledger. Coins are earned when a referred library pays, and spent as a discount on subscriptions. */
class ReferralCoin extends Model
{
    protected $guarded = ['id'];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function referredLibrary(): BelongsTo
    {
        return $this->belongsTo(Library::class, 'referred_library_id');
    }

    public static function balance(int $libraryId): int
    {
        return (int) static::where('library_id', $libraryId)->sum('coins');
    }

    /** Rupees one coin is worth. */
    public static function coinValue(): float
    {
        return max(0.01, (float) BillingSettings::get('coin_value'));
    }

    /** The most coins a library may spend on a subscription priced at $price: capped by balance and by the allowed share of the price. */
    public static function maxRedeemable(int $libraryId, float $price): int
    {
        $share = max(0, min(100, (float) BillingSettings::get('referral_redeem_percent'))) / 100;
        $cap = (int) floor($price * $share / self::coinValue());

        return max(0, min(self::balance($libraryId), $cap));
    }
}
