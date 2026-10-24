<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * Resolves the active library from the URL slug, but only among libraries the signed-in user
 * is verified to belong to. A slug for someone else's library is a 404; a library that is not
 * active (pending, suspended, inactive) is a 403. Registered as Livewire persistent middleware
 * so every component update re-checks it.
 */
class ResolveLibrary
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $slug = $request->route('library');

        abort_unless($user && $user->is_active && is_string($slug), 403);

        $library = $user->accessibleLibraries()->firstWhere('slug', $slug);
        abort_if(! $library, 404);
        abort_unless($library->isActive(), 403, 'This library is not active.');

        Tenancy::set($library);
        View::share('currentLibrary', $library);
        $request->attributes->set('library', $library);

        return $next($request);
    }
}
