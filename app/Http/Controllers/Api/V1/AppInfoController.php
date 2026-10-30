<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\HomeController;
use App\Support\BillingSettings;
use App\Support\Api\AppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Public facts the app shows before or without signing in: app name, support contacts, notices. */
class AppInfoController extends Controller
{
    public function show(): JsonResponse
    {
        $phone = (string) BillingSettings::get('support_phone');
        $name = (string) BillingSettings::get('app_name') ?: config('app.name');

        return $this->ok([
            'app_name' => $name,
            'api_version' => 'v1',
            'support' => [
                'phone' => $phone ?: null,
                'email' => (string) BillingSettings::get('support_email') ?: null,
                'whatsapp_url' => $phone ? HomeController::whatsappLink($phone, (string) $name) : null,
            ],
            'maintenance_notice' => (string) BillingSettings::get('maintenance_notice') ?: null,
            'currency' => (string) BillingSettings::get('default_currency'),
            'upload_limit_kb' => (int) BillingSettings::get('upload_limit_kb'),
            'versions' => collect(AppVersion::PLATFORMS)->keys()->mapWithKeys(fn ($p) => [$p => array_intersect_key(AppVersion::check($p, null), array_flip(['latest_version', 'minimum_version', 'store_url']))])->all(),
        ]);
    }

    /**
     * Force update check. Call it when the app starts (and when it comes back to the foreground).
     * update = required: show a blocking "Update now" screen with store_url; optional: a dismissible prompt; none: carry on.
     * Send the same values as headers (X-App-Platform, X-App-Version) on every call: below the minimum version the
     * API answers 426 with code "update_required".
     */
    public function version(Request $request): JsonResponse
    {
        $d = $request->validate([
            'platform' => ['required', Rule::in(array_keys(AppVersion::PLATFORMS))],
            'version' => ['required', 'string', 'max:20', 'regex:/^\d+(\.\d+){0,3}$/'],
        ], ['version.regex' => 'Send the version like 1.4.2.']);

        return $this->ok(AppVersion::check($d['platform'], $d['version']));
    }
}
