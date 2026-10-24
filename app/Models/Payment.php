<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use BelongsToLibrary;

    public const METHODS = [
        'cash' => 'Cash',
        'upi' => 'UPI',
        'bank_transfer' => 'Bank transfer',
        'card' => 'Card',
        'other' => 'Other',
    ];

    public const COMPLETED = 'completed';
    public const REVERSED = 'reversed';

    protected $attributes = ['status' => self::COMPLETED];

    // Money fields are only ever written by the finance actions, never mass-assigned from a form.
    protected $guarded = ['id', 'library_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date', 'reversed_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isReversed(): bool
    {
        return $this->status === self::REVERSED;
    }

    public function refundedAmount(): string
    {
        return (string) number_format((float) $this->refunds()->sum('amount'), 2, '.', '');
    }
}
