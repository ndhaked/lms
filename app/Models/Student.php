<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    use BelongsToLibrary;

    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const INACTIVE = 'inactive';

    protected $attributes = ['status' => 'active', 'member_type' => 'student'];

    protected $fillable = [
        'name', 'phone', 'email', 'address', 'emergency_contact_name', 'emergency_contact_phone',
        'registered_on', 'photo_path', 'notes', 'status', 'member_type',
    ];

    protected function casts(): array
    {
        return ['registered_on' => 'date'];
    }

    protected static function booted(): void
    {
        // Runs after BelongsToLibrary has stamped library_id.
        static::creating(function (Student $student) {
            $student->student_code ??= static::nextCode((int) $student->library_id);
            $student->registered_on ??= today();
        });
    }

    /** STU-00001, STU-00002 ... numbered per library. */
    public static function nextCode(int $libraryId): string
    {
        $last = (int) static::withoutGlobalScopes()->where('library_id', $libraryId)->max('id');

        do {
            $code = sprintf('STU-%05d', ++$last);
        } while (static::withoutGlobalScopes()->where('library_id', $libraryId)->where('student_code', $code)->exists());

        return $code;
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(StudentMembership::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SeatAllocation::class);
    }

    public function activeAllocation(): HasOne
    {
        return $this->hasOne(SeatAllocation::class)->whereNull('released_at');
    }

    /** Membership running today, if any. */
    public function currentMembership(): ?StudentMembership
    {
        return $this->memberships()->current()->latest('ends_on')->first();
    }
}
