<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A stock-take: copies counted on the shelf and compared with what the register says should be there. */
class InventoryAudit extends Model
{
    use BelongsToLibrary;

    public const OPEN = 'open';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    protected $guarded = ['id', 'library_id'];

    protected $attributes = ['status' => self::OPEN, 'scope_type' => 'all'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'closed_at' => 'datetime', 'unknown_codes' => 'array'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryAuditItem::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function scopeLabel(): string
    {
        return match ($this->scope_type) {
            'category' => 'Category: '.(BookCategory::find((int) $this->scope_value)?->name ?? 'removed'),
            'shelf' => 'Shelf: '.$this->scope_value,
            default => 'Whole library',
        };
    }
}
