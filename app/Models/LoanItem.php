<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use App\Support\CirculationRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One book on one loan. Returned, lost or reversed items stay for the history. */
class LoanItem extends Model
{
    use BelongsToLibrary;

    public const ISSUED = 'issued';
    public const RETURNED = 'returned';
    public const LOST = 'lost';
    public const REVERSED = 'reversed';

    protected $guarded = ['id', 'library_id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'returned_at' => 'datetime', 'last_renewed_at' => 'datetime', 'due_on' => 'date'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function copy(): BelongsTo
    {
        return $this->belongsTo(BookCopy::class, 'book_copy_id');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::ISSUED);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', self::ISSUED)->whereDate('due_on', '<', today());
    }

    public function isOpen(): bool
    {
        return $this->status === self::ISSUED;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_on->lt(today());
    }

    /** Days late today, according to the library's rules (grace and closed days applied). */
    public function lateDays(?string $memberType = null): int
    {
        $rule = CirculationRules::rule($memberType ?? $this->student?->member_type);

        return CirculationRules::overdueDays($this->due_on, now(), $rule['grace_days']);
    }
}
