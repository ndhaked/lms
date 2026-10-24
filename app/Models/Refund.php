<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use BelongsToLibrary;

    protected $guarded = ['id', 'library_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'refunded_on' => 'date'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(StudentMembership::class, 'student_membership_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
