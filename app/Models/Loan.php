<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One issue transaction: a member borrowing one or more books at the same moment. */
class Loan extends Model
{
    use BelongsToLibrary;

    protected $guarded = ['id', 'library_id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoanItem::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
