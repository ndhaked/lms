<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A library's purchase of SMS or WhatsApp message credits, paid manually and approved by the platform team. */
class CreditPurchase extends Model
{
    public const CHANNELS = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp'];
    public const STATUSES = ['pending' => 'Pending review', 'approved' => 'Approved', 'rejected' => 'Rejected'];

    protected $attributes = ['status' => 'pending'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'rate' => 'decimal:4', 'paid_on' => 'date', 'reviewed_at' => 'datetime'];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function proofs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CreditPurchaseProof::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
