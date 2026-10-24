<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member waiting for a title that is out. When a copy comes back it is held ("ready") for the first in the queue. */
class Reservation extends Model
{
    use BelongsToLibrary;

    public const WAITING = 'waiting';
    public const READY = 'ready';
    public const FULFILLED = 'fulfilled';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    protected $guarded = ['id', 'library_id'];

    protected $attributes = ['status' => self::WAITING];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'ready_at' => 'datetime', 'closed_at' => 'datetime', 'expires_on' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function copy(): BelongsTo
    {
        return $this->belongsTo(BookCopy::class, 'book_copy_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::WAITING, self::READY]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::WAITING, self::READY], true);
    }
}
