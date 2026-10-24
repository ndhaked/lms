<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrection extends Model
{
    use BelongsToLibrary;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'library_id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
