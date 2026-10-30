<?php

namespace App\Support\Push;

use App\Models\DeviceToken;
use App\Support\BillingSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications to phones. Two delivery routes, chosen per device by the app when it registers:
 *  - expo: Expo push tokens (React Native apps built with Expo). Works without any server keys; an Expo access
 *    token can be set in Platform settings for extra security.
 *  - fcm:  Firebase Cloud Messaging HTTP v1 (Android, and iOS through APNs in Firebase). Needs the Firebase project id
 *    and a service-account JSON key, stored encrypted in Platform settings.
 * Tokens the services report as no longer registered are deleted. Failures are logged, never thrown:
 * a push problem must not break the action that caused the notification.
 */
class PushSender
{
    public const EXPO_URL = 'https://exp.host/--/api/v2/push/send';

    public static function enabled(): bool
    {
        return (bool) BillingSettings::get('push_enabled', true);
    }

    /** @param Collection<int, DeviceToken> $devices */
    public static function send(Collection $devices, string $title, string $body, array $data = []): int
    {
        if (! self::enabled() || $devices->isEmpty()) {
            return 0;
        }
        // push payload values must be strings for FCM; keep them short
        $data = array_map(fn ($v) => mb_substr((string) $v, 0, 500), array_filter($data, fn ($v) => $v !== null));
        $title = mb_substr($title, 0, 120);
        $body = mb_substr($body, 0, 400);

        $sent = 0;
        foreach ($devices->groupBy('provider') as $provider => $group) {
            try {
                $sent += match ($provider) {
                    'expo' => self::expo($group, $title, $body, $data),
                    'fcm' => self::fcm($group, $title, $body, $data),
                    default => 0,
                };
            } catch (\Throwable $e) {
                Log::warning('Push failed', ['provider' => $provider, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    // ---------- Expo ----------

    protected static function expo(Collection $devices, string $title, string $body, array $data): int
    {
        $sent = 0;
        foreach ($devices->chunk(100) as $chunk) {   // Expo accepts up to 100 messages per request
            $messages = $chunk->values()->map(fn (DeviceToken $d) => [
                'to' => $d->token, 'title' => $title, 'body' => $body, 'data' => (object) $data,
                'sound' => 'default', 'priority' => 'high', 'channelId' => 'default',
            ])->all();

            $request = Http::timeout(10)->acceptJson()->asJson();
            if ($access = self::secret('push_expo_access_token')) {
                $request = $request->withToken($access);
            }
            $response = $request->post(self::EXPO_URL, $messages);
            if (! $response->successful()) {
                Log::warning('Expo push rejected', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

                continue;
            }

            // one ticket per message, in the same order
            foreach ((array) $response->json('data') as $i => $ticket) {
                $device = $chunk->values()[$i] ?? null;
                if (($ticket['status'] ?? '') === 'ok') {
                    $sent++;
                } elseif ($device && ($ticket['details']['error'] ?? '') === 'DeviceNotRegistered') {
                    $device->delete();   // app uninstalled or token expired
                }
            }
        }

        return $sent;
    }

    // ---------- Firebase Cloud Messaging (HTTP v1) ----------

    protected static function fcm(Collection $devices, string $title, string $body, array $data): int
    {
        $project = (string) BillingSettings::get('push_fcm_project_id');
        $token = self::fcmAccessToken();
        if ($project === '' || ! $token) {
            return 0;
        }

        $sent = 0;
        foreach ($devices as $device) {
            $response = Http::timeout(10)->withToken($token)->asJson()->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                'message' => [
                    'token' => $device->token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => (object) $data,
                    'android' => ['priority' => 'high', 'notification' => ['sound' => 'default', 'channel_id' => 'default']],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ],
            ]);

            if ($response->successful()) {
                $sent++;
            } elseif ($response->status() === 404 || str_contains($response->body(), 'UNREGISTERED')) {
                $device->delete();
            } else {
                Log::warning('FCM push rejected', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
            }
        }

        return $sent;
    }

    /** OAuth access token for FCM, made from the service-account key and cached for 50 minutes. */
    protected static function fcmAccessToken(): ?string
    {
        $json = self::secret('push_fcm_credentials');
        $sa = $json ? json_decode($json, true) : null;
        if (! is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            return null;
        }

        return Cache::remember('push.fcm.token.'.md5($sa['client_email']), 3000, function () use ($sa) {
            $now = time();
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $b64(json_encode([
                'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
            ]));
            if (! openssl_sign("{$head}.{$claims}", $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
                return null;
            }
            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$head}.{$claims}.".$b64($signature),
            ]);

            return $response->json('access_token');
        });
    }

    // ---------- settings ----------

    /** Decrypted secret from Platform settings ('' when not set or unreadable). */
    public static function secret(string $key): string
    {
        $value = (string) BillingSettings::get($key);
        if ($value === '') {
            return '';
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return '';
        }
    }

    public static function encrypt(string $value): string
    {
        return $value === '' ? '' : Crypt::encryptString($value);
    }
}
