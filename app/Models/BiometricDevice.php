<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Not tenant-scoped on purpose: a device announces itself with a serial number or token before any library is active.
 * Pages reach devices only through {@see Library::biometricDevices()}.
 */
class BiometricDevice extends Model
{
    protected $guarded = ['id', 'library_id', 'token_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(BiometricPunch::class);
    }

    /** Seen in the last 10 minutes. Devices poll every few seconds, so silence means it is off or cannot reach us. */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(10));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Sets a new access token and returns it. Only the hash is stored, so this is the one time it can be shown. */
    public function issueToken(): string
    {
        $token = 'bio_'.Str::random(40);
        $this->forceFill(['token_hash' => self::hashToken($token)])->save();

        return $token;
    }

    public static function findByToken(string $token): ?self
    {
        return $token === '' ? null : self::where('token_hash', self::hashToken($token))->first();
    }

    /** Serials are compared case-insensitively and without stray spaces. */
    public static function findBySerial(string $serial): ?self
    {
        $serial = trim($serial);

        return $serial === '' ? null : self::whereRaw('lower(serial) = ?', [mb_strtolower($serial)])->first();
    }
}
