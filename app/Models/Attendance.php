<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attendance extends Model
{
    use BelongsToLibrary;

    protected $table = 'attendance';

    protected $guarded = ['id', 'library_id'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'checked_out_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    public function isOpen(): bool
    {
        return $this->checked_out_at === null;
    }

    /** Still inside after the day it started: a missed check-out that needs a correction. */
    public function isStale(): bool
    {
        return $this->isOpen() && $this->checked_in_at->lt(today());
    }

    public function minutes(): ?int
    {
        return $this->checked_out_at ? (int) $this->checked_in_at->diffInMinutes($this->checked_out_at) : null;
    }
}
