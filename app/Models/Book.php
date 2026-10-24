<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A title in the catalogue: either a physical book (has copies) or an e-book (has a file or link). */
class Book extends Model
{
    use BelongsToLibrary;

    public const PHYSICAL = 'physical';
    public const EBOOK = 'ebook';
    public const TYPES = [self::PHYSICAL => 'Book', self::EBOOK => 'E-book'];

    protected $guarded = ['id', 'library_id', 'created_by'];

    protected $attributes = ['type' => self::PHYSICAL, 'status' => 'active', 'allow_download' => true];

    protected function casts(): array
    {
        return [
            'tags' => 'array', 'purchase_price' => 'decimal:2', 'purchased_on' => 'date', 'licence_expires_on' => 'date',
            'allow_download' => 'boolean', 'publication_year' => 'integer', 'ebook_size' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BookCategory::class, 'category_id');
    }

    public function copies(): HasMany
    {
        return $this->hasMany(BookCopy::class);
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(EbookAccessLog::class);
    }

    public function isEbook(): bool
    {
        return $this->type === self::EBOOK;
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /** The licence has run out: the e-book can no longer be opened. */
    public function licenceExpired(): bool
    {
        return $this->licence_expires_on !== null && $this->licence_expires_on->lt(today());
    }

    public function hasFile(): bool
    {
        return filled($this->ebook_path);
    }

    /** Shelf / rack / section as one readable line. */
    public function locationText(): string
    {
        return collect([$this->location, $this->section ? 'Section '.$this->section : null, $this->rack ? 'Rack '.$this->rack : null, $this->shelf ? 'Shelf '.$this->shelf : null])->filter()->implode(' · ');
    }

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $isbn = \App\Support\Isbn::normalize($term);

        return $query->where(function ($q) use ($term, $isbn) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $q->where('title', 'like', $like)->orWhere('author', 'like', $like)->orWhere('publisher', 'like', $like)->orWhere('isbn', 'like', '%'.$isbn.'%');
            // a scanned barcode or typed accession number finds the title it belongs to
            $q->orWhereHas('copies', fn ($c) => $c->where('accession_no', $term)->orWhere('barcode', $term));
        });
    }
}
