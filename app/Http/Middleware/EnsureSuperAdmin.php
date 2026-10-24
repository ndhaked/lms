<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Platform area: only active Super Admin accounts. Library roles never grant platform access. */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        abort_unless($user && $user->is_active && $user->is_super_admin, 403);

        return $next($request);
    }
}
