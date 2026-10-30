<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One SMS or WhatsApp message sent to a student or parent, and the credits it used. */
class MessageLog extends Model
{
    use BelongsToLibrary;

    protected $guarded = ['id', 'library_id'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
