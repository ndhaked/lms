<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "I have paid" details sent by a student. It is only a claim: no money is recorded and no balance
 * changes until staff approve it, which creates a normal Payment through RecordPayment.
 */
class PaymentSubmission extends Model
{
    use BelongsToLibrary;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $guarded = ['id', 'library_id', 'status', 'reviewed_by', 'reviewed_at', 'review_note', 'payment_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date', 'reviewed_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(StudentMembership::class, 'student_membership_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
