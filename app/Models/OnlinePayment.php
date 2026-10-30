<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Razorpay order started by a library (a plan, or SMS / WhatsApp credits), and what became of it. */
class OnlinePayment extends Model
{
    public const STATUSES = ['created' => 'Waiting for payment', 'paid' => 'Paid', 'failed' => 'Failed'];

    protected $attributes = ['status' => 'created', 'currency' => 'INR', 'coins_used' => 0];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'fulfilled_at' => 'datetime'];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /** Amount in paise, which is what Razorpay works in. */
    public function paise(): int
    {
        return (int) round((float) $this->amount * 100);
    }

    public function describe(): string
    {
        return $this->purpose === 'credits'
            ? number_format((int) $this->credits).' '.CreditPurchase::CHANNELS[$this->channel].' credits'
            : ($this->plan?->name ?? 'Plan').' · '.ucfirst((string) $this->billing_cycle);
    }
}
