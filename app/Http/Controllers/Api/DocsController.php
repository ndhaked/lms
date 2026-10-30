<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api\OpenApiGenerator;
use App\Support\BillingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Swagger UI for the mobile API (/api/docs) and its OpenAPI document (/api/docs/openapi.json), generated from the code.
 * The Super Admin switches it in Platform settings → Mobile app: Automatic (on except on the live site), On or Off.
 */
class DocsController extends Controller
{
    public static function enabled(): bool
    {
        return match ((string) BillingSettings::get('api_docs')) {
            'on' => true,
            'off' => false,
            default => ! app()->isProduction(),
        };
    }

    protected function guard(): void
    {
        abort_unless(self::enabled(), 404);
    }

    public function ui()
    {
        $this->guard();

        return response()->view('api.docs', ['specUrl' => route('api.docs.spec')]);
    }

    public function spec(): JsonResponse
    {
        $this->guard();
        $spec = app()->isProduction()
            ? Cache::remember('api.openapi.v1', 3600, fn () => app(OpenApiGenerator::class)->build())
            : app(OpenApiGenerator::class)->build();

        return response()->json($spec, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
