<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Push notification registration for this phone. Call after sign-in and whenever the push token changes. */
class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255', self::tokenRule($request->input('provider'))],
            'provider' => ['required', Rule::in(DeviceToken::PROVIDERS)],
            'platform' => ['required', Rule::in(DeviceToken::PLATFORMS)],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ]);

        self::register($request->user(), $request->user()->currentAccessToken()->id, $data['token'], $data['provider'], $data['platform'], $data['device_name'] ?? null, $data['app_version'] ?? null);

        return $this->message('This device will receive notifications.', [], 201);
    }

    /** Stop push notifications on this phone (the app's notification switch). */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);
        $request->user()->devices()->where('token', $data['token'])->delete();

        return $this->message('Notifications turned off for this device.');
    }

    /**
     * Validation rule for a push token: an Expo token must look like ExponentPushToken[…]; a Firebase token is a long
     * string without spaces. Checked with the other fields, before anything is saved. An empty token is allowed
     * (the field is optional at sign-in): the person is simply signed in without push notifications.
     */
    public static function tokenRule(?string $provider): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($provider) {
            $value = (string) $value;
            if ($value === '') {
                return;
            }
            if ($provider === 'expo' && ! preg_match('/^(ExponentPushToken|ExpoPushToken)\[[^\]\s]+\]$/', $value)) {
                $fail('That is not an Expo push token. It looks like ExponentPushToken[xxxxxxxx].');
            } elseif ($provider === 'fcm' && ! preg_match('/^[A-Za-z0-9_:\-]{20,255}$/', $value)) {
                $fail('That is not a Firebase (FCM) registration token.');
            }
        };
    }

    /**
     * Links a push token to the person and the sign-in it came with. A token is unique to one app install,
     * so if someone else used this phone before, the token moves to the new person.
     */
    public static function register(User $user, int $accessTokenId, string $token, string $provider, string $platform, ?string $name, ?string $version): DeviceToken
    {
        $device = DeviceToken::firstOrNew(['token' => $token]);
        $device->forceFill([
            'user_id' => $user->id, 'provider' => $provider, 'platform' => $platform,
            'device_name' => $name, 'app_version' => $version, 'access_token_id' => $accessTokenId, 'last_seen_at' => now(),
        ])->save();

        return $device;
    }
}
