<?php

namespace App\Livewire\Concerns;

/**
 * Server-side permission checks for Livewire components. Hiding a button is cosmetic; every
 * page load and every action calls one of these, because actions can be invoked directly.
 */
trait AuthorizesActions
{
    protected function authorizePermission(string $permission): void
    {
        abort_unless(auth()->user()?->can($permission), 403);
    }

    protected function can(string $permission): bool
    {
        return auth()->user()?->can($permission) ?? false;
    }
}
