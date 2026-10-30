<?php

namespace App\Models;

use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Library extends Model
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const INACTIVE = 'inactive';

    public const STATUSES = [
        self::PENDING => 'Pending activation',
        self::ACTIVE => 'Active',
        self::SUSPENDED => 'Suspended',
        self::INACTIVE => 'Inactive',
    ];

    // Mirror the column defaults so a freshly created model behaves like one loaded from the database.
    protected $attributes = ['status' => self::ACTIVE, 'timezone' => 'Asia/Kolkata', 'currency' => 'INR'];

    protected $fillable = [
        'name', 'slug', 'logo_path', 'address', 'phone', 'email',
        'opening_time', 'closing_time', 'timezone', 'currency', 'status',
    ];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime'];
    }

    public function referrer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_id');
    }

    public function referrals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'referred_by_id');
    }

    protected static function booted(): void
    {
        static::creating(function (Library $library) {
            $library->slug ??= static::uniqueSlug($library->name);
        });

        // The code needs the id, so it is assigned right after the insert.
        static::created(function (Library $library) {
            if (! $library->code) {
                $library->forceFill(['code' => sprintf('LIB-%04d', $library->id)])->saveQuietly();
            }
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'library';
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function biometricDevices(): HasMany
    {
        return $this->hasMany(BiometricDevice::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_active')->withTimestamps();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class)->latest('created_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LibraryNote::class)->latest();
    }

    // Platform-level counts must see every tenant's rows, so the tenant scope is removed on purpose.
    public function students(): HasMany
    {
        return $this->hasMany(Student::class)->withoutGlobalScope('library');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class)->withoutGlobalScope('library');
    }

    /** Active people with access who are not student-only logins (what a plan's staff limit counts). */
    public function staffCount(): int
    {
        return $this->users()->wherePivot('is_active', true)->get()->filter(function (User $u) {
            // roles are stored per library, so read them inside this library's scope
            return Tenancy::run($this, fn () => $u->unsetRelation('roles')->roles->isEmpty()
                || $u->roles->contains(fn ($r) => $r->name !== LibraryRoles::STUDENT));
        })->count();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->latest('id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
