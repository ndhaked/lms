<?php

namespace App\Http\Middleware\Api;

use App\Support\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same subscription policy as the web panel (EnforceSubscription), for the API:
 * while a library's subscription is suspended, cancelled or expired past its grace period,
 * only the subscription, help, contact, referral and profile endpoints answer. Read-only libraries
 * are let through; their writes are refused by the models (ReadOnlyLibraryException, a 403).
 */
class EnforceApiSubscription
{
    /** Route names (inside api.v1.library.) that always work, so the owner can see and fix billing. */
    protected const ALWAYS = ['subscription.*', 'help.*', 'contact', 'refer.*', 'context', 'theme.*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (Entitlements::state() !== 'restricted') {
            return $next($request);
        }

        $allowed = array_map(fn ($n) => 'api.v1.library.'.$n, self::ALWAYS);
        if ($request->routeIs(...$allowed)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Your subscription needs attention. Open My subscription to renew.',
            'code' => 'subscription_restricted',
        ], 402);
    }
}
