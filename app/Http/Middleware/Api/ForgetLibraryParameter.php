<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After `library` has resolved and checked {library}, drop it from the route parameters so API controller
 * methods receive only their own ids (students/{student} -> show(int $student)). The library is in Tenancy.
 */
class ForgetLibraryParameter
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->route()?->forgetParameter('library');

        return $next($request);
    }
}
