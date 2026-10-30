<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;

/** A WhatsApp message a library keeps ready to send; each library edits its own copy. */
class WhatsappTemplate extends Model
{
    use BelongsToLibrary;

    protected $guarded = ['id', 'library_id'];
}
