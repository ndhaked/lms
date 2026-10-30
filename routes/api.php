<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile app API, version 1   (base URL: /api/v1)
|--------------------------------------------------------------------------
| Authentication: "Authorization: Bearer <token>" from POST /auth/login.
| Library endpoints live under /libraries/{library}/... where {library} is the slug from the login response;
| the `library` middleware checks the person belongs to it and scopes every query to it.
| Every endpoint checks its own permission. Errors are JSON: { message, errors? }.
*/

Route::prefix('v1')->name('api.v1.')->middleware(['throttle:api', 'api.version'])->group(function () {

    // ---------- sign-in (no token yet) ----------
    Route::middleware('throttle:api-auth')->group(function () {
        Route::post('auth/login', [V1\AuthController::class, 'login'])->name('auth.login');
        Route::post('auth/forgot-password', [V1\AuthController::class, 'forgotPassword'])->name('auth.forgot');
    });

    // public app info (support contacts, FAQ language list) needs no token
    Route::get('app/info', [V1\AppInfoController::class, 'show'])->name('app.info');
    Route::get('app/version', [V1\AppInfoController::class, 'version'])->name('app.version');   // force update check

    Route::middleware(['auth:sanctum', 'api.user'])->group(function () {

        // ---------- account ----------
        Route::get('auth/me', [V1\AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [V1\AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/logout-all', [V1\AuthController::class, 'logoutAll'])->name('auth.logout-all');
        Route::get('auth/sessions', [V1\AuthController::class, 'sessions'])->name('auth.sessions');
        Route::delete('auth/sessions/{id}', [V1\AuthController::class, 'revokeSession'])->whereNumber('id')->name('auth.sessions.revoke');
        Route::put('me', [V1\ProfileController::class, 'update'])->name('me.update');
        Route::put('me/password', [V1\ProfileController::class, 'password'])->name('me.password');

        // ---------- push notifications ----------
        Route::post('devices', [V1\DeviceController::class, 'store'])->name('devices.store');
        Route::delete('devices', [V1\DeviceController::class, 'destroy'])->name('devices.destroy');

        Route::get('notifications', [V1\NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread-count', [V1\NotificationController::class, 'unreadCount'])->name('notifications.unread');
        Route::post('notifications/read-all', [V1\NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{id}/read', [V1\NotificationController::class, 'read'])->name('notifications.read');

        // ---------- inside one library ----------
        Route::prefix('libraries/{library}')->name('library.')->middleware(['library', 'api.subscription', 'api.forget-library'])->group(base_path('routes/api-library.php'));
    });
});
