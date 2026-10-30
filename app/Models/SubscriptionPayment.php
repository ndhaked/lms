<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPayment extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const INFO_REQUESTED = 'info_requested';

    public const STATUSES = [
        self::PENDING => 'Pending review', self::APPROVED => 'Approved', self::REJECTED => 'Rejected', self::INFO_REQUESTED => 'More information needed',
    ];

    /** Ways a library can report a payment it made to us by hand. */
    public const MANUAL_METHODS = ['bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'cash' => 'Cash', 'other' => 'Other'];

    public const METHODS = self::MANUAL_METHODS + ['razorpay' => 'Razorpay (online)'];

    protected $attributes = ['status' => self::PENDING];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'breakdown' => 'array', 'paid_on' => 'date', 'reviewed_at' => 'datetime', 'period_starts_on' => 'date', 'period_ends_on' => 'date'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(SubscriptionPaymentProof::class);
    }

    public function invoice()
    {
        return $this->hasOne(SubscriptionInvoice::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::INFO_REQUESTED], true);
    }
}
