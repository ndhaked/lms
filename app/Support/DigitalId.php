<?php

namespace App\Support;

use App\Models\Student;

/**
 * The student's digital ID in the app: a QR that changes every minute. It holds the library, the student and an
 * expiry time, signed with the app key (HMAC-SHA256), so it cannot be forged or edited, and a screenshot of it
 * stops working after a minute. Staff scan it to check the student in or out.
 */
class DigitalId
{
    public const TTL_SECONDS = 60;

    /** @return array{token: string, expires_at: string, ttl: int} */
    public static function make(Student $student): array
    {
        $exp = now()->addSeconds(self::TTL_SECONDS);
        $payload = self::b64(json_encode(['l' => $student->library_id, 's' => $student->id, 'e' => $exp->timestamp]));

        return ['token' => 'LMSID.'.$payload.'.'.self::sign($payload), 'expires_at' => $exp->toIso8601String(), 'ttl' => self::TTL_SECONDS];
    }

    /** The student id in a valid, unexpired token for this library, or null. */
    public static function studentId(string $token, int $libraryId): ?int
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3 || $parts[0] !== 'LMSID' || ! hash_equals(self::sign($parts[1]), $parts[2])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (! is_array($data) || (int) ($data['l'] ?? 0) !== $libraryId || (int) ($data['e'] ?? 0) < now()->timestamp) {
            return null;
        }

        return (int) ($data['s'] ?? 0) ?: null;
    }

    protected static function sign(string $payload): string
    {
        return self::b64(hash_hmac('sha256', 'digital-id|'.$payload, (string) config('app.key'), true));
    }

    protected static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
