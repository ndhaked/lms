<?php

namespace App\Http\Middleware;

use App\Support\Entitlements;
use Closure;
use Illuminate\Http\Request;

/** Route middleware `module:attendance`: the library's plan must include that optional module. */
class EnsureModule
{
    public function handle(Request $request, Closure $next, string $module)
    {
        // "books|ebooks" means: any one of these modules is enough
        abort_unless(collect(explode('|', $module))->contains(fn ($m) => Entitlements::hasModule($m)), 403, 'This feature is not included in your plan.');

        return $next($request);
    }
}
