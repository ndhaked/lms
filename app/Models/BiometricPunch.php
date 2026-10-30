<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricPunch extends Model
{
    use BelongsToLibrary;

    public const RESULTS = [
        'checked_in' => 'Checked in',
        'checked_out' => 'Checked out',
        'ignored' => 'Ignored',
        'rejected' => 'Not accepted',
        'unknown' => 'Unknown ID',
    ];

    /** The log is written even when the library is read-only, so no punch is lost. */
    public bool $allowedWhenReadOnly = true;

    protected $guarded = ['id', 'library_id'];

    protected function casts(): array
    {
        return ['punched_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'biometric_device_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
