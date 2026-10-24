<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;

class EbookAccessLog extends Model
{
    use BelongsToLibrary;

    public const UPDATED_AT = null;

    public bool $allowedWhenReadOnly = true;   // opening an e-book is reading, not changing library data

    protected $guarded = ['id', 'library_id'];
}
