<?php

use App\Http\Middleware\EnforceSubscription;
use App\Http\Middleware\EnsureModule;
use App\Http\Middleware\EnsurePlatformPermission;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveLibrary;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'super-admin' => EnsureSuperAdmin::class,
            'platform' => EnsurePlatformPermission::class,
            'library' => ResolveLibrary::class,
            'subscription' => EnforceSubscription::class,
            'module' => EnsureModule::class,
        ]);

        $middleware->web(append: [\App\Http\Middleware\SecurityHeaders::class]);

        // Send signed-out visitors to our own login page.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
