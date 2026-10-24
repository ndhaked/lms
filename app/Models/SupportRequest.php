<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A library asking the platform team for help. Library pages always filter by their own library_id. */
class SupportRequest extends Model
{
    protected $attributes = ['status' => 'open'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime'];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
