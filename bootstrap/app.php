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
        api: __DIR__.'/../routes/api.php',
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
            // mobile app API (routes/api.php)
            'api.user' => \App\Http\Middleware\Api\EnsureApiUser::class,
            'api.subscription' => \App\Http\Middleware\Api\EnforceApiSubscription::class,
            'api.forget-library' => \App\Http\Middleware\Api\ForgetLibraryParameter::class,
            'api.version' => \App\Http\Middleware\Api\EnforceAppVersion::class,
        ]);

        // Every API request is answered in JSON, even when the app forgets the Accept header.
        $middleware->api(prepend: [\App\Http\Middleware\Api\ForceJson::class], append: [\App\Http\Middleware\SecurityHeaders::class]);

        $middleware->web(append: [\App\Http\Middleware\SecurityHeaders::class]);
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'iclock/*', 'api/biometric/*']);

        // Send signed-out visitors to our own login page.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('workspace'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API: errors are always JSON, and a broken business rule is a 422 with a message that is safe to show.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (\App\Exceptions\BusinessRuleException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Not found.'], 404);
            }
        });
    })->create();
