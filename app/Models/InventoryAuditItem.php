<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAuditItem extends Model
{
    use BelongsToLibrary;

    public $timestamps = false;

    protected $guarded = ['id', 'library_id'];

    protected function casts(): array
    {
        return ['expected' => 'boolean', 'scanned_at' => 'datetime'];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(InventoryAudit::class, 'inventory_audit_id');
    }

    public function copy(): BelongsTo
    {
        return $this->belongsTo(BookCopy::class, 'book_copy_id');
    }
}
