<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One physical copy of a book, with its own accession number and barcode. */
class BookCopy extends Model
{
    use BelongsToLibrary;

    public const AVAILABLE = 'available';
    public const ISSUED = 'issued';
    public const RESERVED = 'reserved';
    public const LOST = 'lost';
    public const DAMAGED = 'damaged';
    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::AVAILABLE => 'Available', self::ISSUED => 'Issued', self::RESERVED => 'Reserved',
        self::LOST => 'Lost', self::DAMAGED => 'Damaged', self::WITHDRAWN => 'Withdrawn',
    ];

    protected $guarded = ['id', 'library_id', 'status'];

    protected $attributes = ['status' => self::AVAILABLE];

    protected function casts(): array
    {
        return ['purchase_price' => 'decimal:2', 'purchased_on' => 'date', 'withdrawn_on' => 'date'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BookCopyEvent::class)->orderByDesc('id');
    }

    /** Copies that count as part of the working stock (not lost, not withdrawn). */
    public function scopeInStock($query)
    {
        return $query->whereNotIn($query->getModel()->qualifyColumn('status'), [self::LOST, self::WITHDRAWN]);
    }
}
