<?php

namespace App\Http\Middleware\Api;

use App\Support\Api\AppVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force update on the server side: when the app sends X-App-Platform (android | ios) and X-App-Version and that
 * version is below the minimum, every call is answered 426 with code "update_required" and the store link,
 * so an old app cannot keep working even if it skips the start-up check. Requests without these headers
 * (Swagger, Postman, the web) are not affected. The version check endpoints themselves always answer.
 */
class EnforceAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $platform = strtolower((string) $request->header('X-App-Platform'));
        $version = (string) $request->header('X-App-Version');

        if (isset(AppVersion::PLATFORMS[$platform]) && $version !== '' && ! $request->routeIs('api.v1.app.*')) {
            $check = AppVersion::check($platform, $version);
            if ($check['force_update']) {
                return response()->json(['message' => $check['message'], 'code' => 'update_required'] + $check, 426);
            }
        }

        return $next($request);
    }
}
