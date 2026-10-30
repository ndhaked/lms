<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:sanctum on every API request. A token stops working the moment its user is deactivated,
 * and the token's "last used" time is what the Devices list shows.
 * Platform (Super Admin) accounts use the web panel; the app is for library staff and students.
 */
class EnsureApiUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            $user?->currentAccessToken()?->delete();
            abort(401, 'Your account is not active. Contact your library.');
        }
        abort_if($user->is_super_admin, 403, 'Platform administrators use the web panel.');

        return $next($request);
    }
}
