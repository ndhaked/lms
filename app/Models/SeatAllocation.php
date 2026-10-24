<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatAllocation extends Model
{
    use BelongsToLibrary;

    protected $guarded = ['id', 'library_id'];

    protected function casts(): array
    {
        return ['allocated_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(StudentMembership::class, 'student_membership_id');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }
}
