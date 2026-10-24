<?php

namespace App\Actions\Billing\Concerns;

trait AuthorizesPlatform
{
    protected function authorizePlatform(string $permission): void
    {
        abort_unless(auth()->user()?->hasPlatformPermission($permission), 403);
    }
}
