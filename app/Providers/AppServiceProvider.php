<?php

namespace App\Providers;

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveLibrary;
use Illuminate\Auth\Middleware\Authenticate;
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
