<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only: audit rows can be created but never changed or removed through the application. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs cannot be modified.'));
        static::deleting(fn () => throw new LogicException('Audit logs cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function library()
    {
        return $this->belongsTo(Library::class);
    }

    public static function record(string $action, ?Model $subject = null, array $properties = [], ?int $libraryId = null): self
    {
        return static::create([
            'library_id' => $libraryId ?? getPermissionsTeamId(),
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }
}
