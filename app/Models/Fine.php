<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fine extends Model
{
    use BelongsToLibrary;

    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const WAIVED = 'waived';

    public const TYPES = ['overdue' => 'Overdue', 'lost' => 'Lost book', 'damaged' => 'Damaged book', 'manual' => 'Other'];

    protected $guarded = ['id', 'library_id'];

    protected $attributes = ['status' => self::PENDING, 'waived_amount' => '0.00', 'paid_amount' => '0.00'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'waived_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'settled_at' => 'datetime', 'paid_on' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function loanItem(): BelongsTo
    {
        return $this->belongsTo(LoanItem::class);
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /** What is still to be paid: amount, less anything waived or already paid. */
    public function outstanding(): string
    {
        return bcsub(bcsub((string) $this->amount, (string) $this->waived_amount, 2), (string) $this->paid_amount, 2);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }
}
