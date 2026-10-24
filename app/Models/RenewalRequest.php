<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student asks the library to renew or change their membership. Staff decide; nothing is bought automatically. */
class RenewalRequest extends Model
{
    use BelongsToLibrary;

    public bool $allowedWhenReadOnly = true;

    protected $attributes = ['status' => 'pending'];

    protected $guarded = ['id', 'library_id'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }
}
