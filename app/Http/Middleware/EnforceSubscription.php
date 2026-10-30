<?php

namespace App\Http\Middleware;

use App\Support\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * Applies the subscription policy after ResolveLibrary has set the active library:
 *  - trial / active / grace period: normal use (grace and trial-ending states show a banner);
 *  - suspended, cancelled, pending, or expired beyond the grace period: only the billing pages work.
 * Data is never deleted or hidden from the owner; the billing page stays reachable so it can be fixed.
 */
class EnforceSubscription
{
    public function handle(Request $request, Closure $next)
    {
        $subscription = Entitlements::subscription();
        $state = Entitlements::state();

        View::share('subscription', $subscription);
        View::share('subscriptionState', $state);

        if ($state !== 'restricted' || $request->routeIs('app.subscription.*', 'app.help.*', 'app.contact.*', 'app.refer.*', 'app.profile.*')) {
            return $next($request);
        }

        // A Livewire update has no page to redirect to: refuse it; page loads go to the billing page.
        if ($request->headers->has('X-Livewire') || $request->expectsJson()) {
            abort(403, 'Your subscription needs attention. Open the Subscription page.');
        }

        return redirect()->route('app.subscription.index', ['library' => $request->route('library')]);
    }
}
