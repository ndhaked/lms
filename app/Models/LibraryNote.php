<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Internal support note written by platform staff; never shown to the library. */
class LibraryNote extends Model
{
    protected $fillable = ['library_id', 'user_id', 'body'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
