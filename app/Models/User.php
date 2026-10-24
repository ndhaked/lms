<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'platform_permissions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Platform (Super Admin) permissions. A super admin with no explicit list is unrestricted;
     * otherwise only the listed permissions apply. Library users never hold any.
     */
    public function hasPlatformPermission(string $permission): bool
    {
        if (! $this->is_super_admin || ! $this->is_active) {
            return false;
        }

        $list = $this->platform_permissions;

        return $list === null || in_array($permission, $list, true);
    }

    public function libraries(): BelongsToMany
    {
        return $this->belongsToMany(Library::class)->withPivot('is_active')->withTimestamps();
    }

    /** Libraries this user is verified to belong to (active membership, library not deactivated). */
    public function accessibleLibraries(): Collection
    {
        return $this->libraries()
            ->wherePivot('is_active', true)
            ->where('libraries.status', '!=', Library::INACTIVE)
            ->get();
    }

    public function canAccessLibrary(Library $library): bool
    {
        return $this->is_active && $this->accessibleLibraries()->contains('id', $library->getKey());
    }
}
