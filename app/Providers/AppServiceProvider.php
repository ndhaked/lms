<?php

namespace App\Providers;

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveLibrary;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // E-book files can be up to 50 MB (the form still enforces its own limits per field).
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:51200']]);

        if ($this->app->isProduction()) {
            \Illuminate\Support\Facades\URL::forceHttps();
        }

        // Mobile API limits: per signed-in user (or per IP before sign-in); sign-in and password reset are much stricter.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('api-auth', fn (Request $r) => [
            Limit::perMinute(10)->by($r->ip()),
            Limit::perMinute(5)->by(mb_strtolower((string) $r->input('email')).'|'.$r->ip()),
        ]);
        RateLimiter::for('api-uploads', fn (Request $r) => Limit::perMinute(20)->by($r->user()?->id ?: $r->ip()));

        // Livewire component updates are separate requests: re-run the access checks on each of them.
        Livewire::addPersistentMiddleware([
            Authenticate::class,
            EnsureSuperAdmin::class,
            ResolveLibrary::class,
            \App\Http\Middleware\EnforceSubscription::class,
            \App\Http\Middleware\EnsureModule::class,
            \App\Http\Middleware\EnsurePlatformPermission::class,
        ]);
    }
}
