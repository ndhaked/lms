<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SubscriptionPlan;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use App\Support\Themes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The library the app has opened: who the person is here, what they may do, the plan's modules and the theme. */
class LibraryController extends Controller
{
    public function context(Request $request): JsonResponse
    {
        $library = $this->library();
        $s = Entitlements::subscription();

        return $this->ok([
            'library' => [
                'id' => $library->id, 'slug' => $library->slug, 'name' => $library->name, 'code' => $library->code,
                'address' => $library->address, 'phone' => $library->phone, 'email' => $library->email,
                'opening_time' => $library->opening_time, 'closing_time' => $library->closing_time,
                'timezone' => $library->timezone, 'currency' => $library->currency,
                'logo_url' => $library->logo_path ? asset('storage/'.$library->logo_path) : null,
            ],
            'me' => AuthController::libraryPayload($request->user(), $library, withPermissions: true),
            'modules' => collect(SubscriptionPlan::MODULES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'enabled' => Entitlements::hasModule($key)])->values()->all(),
            'subscription' => [
                'state' => Entitlements::state(),            // full | warn | restricted | readonly | none
                'read_only' => Entitlements::readOnly(),
                'plan' => $s?->plan_name,
                'status' => $s?->effectiveStatus(),
                'covered_until' => $this->date($s?->coveredUntil()),
                'days_left' => $s?->daysLeft(),
            ],
            'credits' => ['sms' => (int) $library->sms_credits, 'whatsapp' => (int) $library->whatsapp_credits],
            'theme' => $this->themePayload(),
            'settings' => [
                'renewal_window_days' => (int) LibrarySettings::get('renewal_window_days'),
                'seat_prefix' => (string) LibrarySettings::get('seat_prefix'),
            ],
        ]);
    }

    public function theme(): JsonResponse
    {
        return $this->ok($this->themePayload());
    }

    /** Same rule as the web theme picker: people who manage settings choose it, and it applies to everyone in the library. */
    public function updateTheme(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $this->writable();
        $data = $request->validate(['theme' => ['required', Rule::in(Themes::pickable(Themes::key()))]]);
        LibrarySettings::update(['theme' => $data['theme']]);

        return $this->message('Theme changed.', $this->themePayload());
    }

    protected function themePayload(): array
    {
        $current = Themes::key();

        return [
            'current' => $current,
            'can_change' => $this->can('settings.manage'),
            'choices' => collect(Themes::pickable($current))->map(fn ($k) => [
                'key' => $k, 'label' => Themes::ALL[$k]['label'], 'shades' => array_combine([50, 100, 200, 300, 400, 500, 600, 700, 800, 900], Themes::ALL[$k]['shades']),
                'swatch' => Themes::ALL[$k]['swatch'],
            ])->values()->all(),
        ];
    }
}
