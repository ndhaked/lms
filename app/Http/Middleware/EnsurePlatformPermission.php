<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Route middleware `platform:payments.approve`: granular permission for platform administrators. */
class EnsurePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        abort_unless($request->user()?->hasPlatformPermission($permission), 403);

        return $next($request);
    }
}
