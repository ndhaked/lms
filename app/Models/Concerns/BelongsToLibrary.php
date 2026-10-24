<?php

namespace App\Models\Concerns;

use App\Models\Library;
use App\Support\Entitlements;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to every tenant-owned model (needs a library_id column).
 * Reads are always scoped to the active library; writes are stamped with it.
 * The library id is never taken from client input.
 */
trait BelongsToLibrary
{
    public static function bootBelongsToLibrary(): void
    {
        static::addGlobalScope('library', function (Builder $query) {
            // Fail closed: with no active library, return nothing rather than everything.
            $query->where($query->getModel()->qualifyColumn('library_id'), Tenancy::currentId() ?? 0);
        });

        // Read-only expiry policy: no tenant data can be written, whichever page or action tries it.
        static::saving(fn ($model) => ($model->allowedWhenReadOnly ?? false) ?: Entitlements::assertWritable());
        static::deleting(fn ($model) => ($model->allowedWhenReadOnly ?? false) ?: Entitlements::assertWritable());

        static::creating(function ($model) {
            $model->library_id = Tenancy::currentId()
                ?? throw new \LogicException('No active library for tenant-owned record.');
        });
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }
}
