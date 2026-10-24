<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    use BelongsToLibrary;

    protected $fillable = ['name'];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
