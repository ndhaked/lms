<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** History of one copy: acquired, status changes, issues, returns, audits, disposal. */
class BookCopyEvent extends Model
{
    use BelongsToLibrary;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'library_id'];

    public function copy(): BelongsTo
    {
        return $this->belongsTo(BookCopy::class, 'book_copy_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function log(BookCopy $copy, string $type, ?string $from = null, ?string $to = null, ?string $note = null): self
    {
        return static::create(['book_copy_id' => $copy->id, 'user_id' => auth()->id(), 'type' => $type, 'from_status' => $from, 'to_status' => $to, 'note' => $note ? mb_substr($note, 0, 500) : null]);
    }
}
