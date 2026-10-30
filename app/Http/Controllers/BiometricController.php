<?php

namespace App\Http\Controllers;

use App\Actions\Biometric\RecordBiometricPunch;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * How attendance machines talk to us. Two ways in:
 *  - the ZKTeco / eSSL / Realtime "push" (ADMS) protocol, which these machines speak natively once their cloud server
 *    address is set. The machine is recognised by its serial number, which the library registered beforehand.
 *  - a small JSON endpoint for anything else (a card reader, a tablet kiosk, a custom box), using a per-device token.
 * Neither uses a login or CSRF token. Unknown serials and tokens are refused and nothing is stored for them.
 */
class BiometricController extends Controller
{
    // ---- ZKTeco / ADMS push protocol ----

    /** The machine says hello (GET) or uploads records (POST). */
    public function cdata(Request $request): Response
    {
        $device = $this->deviceBySerial($request);
        if (! $device) {
            return $this->text('Unknown device', 404);
        }

        if ($request->isMethod('get')) {
            // Tells the machine how to behave: send each punch straight away, and every record, not only new ones.
            return $this->text(implode("\n", [
                "GET OPTION FROM: {$device->serial}", 'Stamp=9999', 'OpLogStamp=9999', 'PhotoStamp=9999', 'ErrorDelay=30', 'Delay=10',
                'TransTimes=00:00;14:00', 'TransInterval=1', 'TransFlag=TransData AttLog', 'Realtime=1', 'Encrypt=0',
            ]));
        }

        $count = 0;
        if (strtoupper((string) $request->query('table')) === 'ATTLOG') {
            foreach (preg_split('/\r\n|\r|\n/', (string) $request->getContent()) as $line) {
                // "PIN<TAB>2026-10-28 09:15:02<TAB>status<TAB>verify..." (some firmware uses spaces instead of tabs)
                if (preg_match('/^\s*(\S+)\s+(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $line, $m)) {
                    $this->punch($device, $m[1], $m[2].' '.$m[3]);
                    $count++;
                }
            }
        }

        return $this->text("OK: {$count}");
    }

    /** The machine asks whether we have commands for it. We never do. */
    public function getRequest(Request $request): Response
    {
        return $this->deviceBySerial($request) ? $this->text('OK') : $this->text('Unknown device', 404);
    }

    /** The machine reports the result of a command. Nothing to do. */
    public function deviceCommand(Request $request): Response
    {
        return $this->deviceBySerial($request) ? $this->text('OK') : $this->text('Unknown device', 404);
    }

    // ---- JSON endpoint ----

    /** POST {pin, time?} or {punches: [{pin, time?}, ...]} with "Authorization: Bearer <device token>". */
    public function punches(Request $request): JsonResponse
    {
        $device = BiometricDevice::findByToken((string) $request->bearerToken());
        if (! $device) {
            return response()->json(['message' => 'Invalid or missing device token.'], 401);
        }
        $device->forceFill(['last_seen_at' => now()])->save();

        $rows = $request->input('punches');
        $rows = is_array($rows) ? $rows : [['pin' => $request->input('pin'), 'time' => $request->input('time')]];
        if (count($rows) > 200) {
            return response()->json(['message' => 'Send at most 200 punches at a time.'], 422);
        }

        $results = [];
        foreach ($rows as $row) {
            $pin = is_array($row) ? (string) ($row['pin'] ?? '') : '';
            if ($pin === '') {
                $results[] = ['pin' => '', 'result' => 'rejected', 'message' => 'pin is required'];

                continue;
            }
            $p = $this->punch($device, $pin, is_array($row) ? ($row['time'] ?? null) : null);
            $results[] = ['pin' => $pin, 'result' => $p?->result ?? 'rejected', 'message' => $p?->message ?? 'Could not read the time.'];
        }

        return response()->json(['results' => $results]);
    }

    // ---- helpers ----

    protected function deviceBySerial(Request $request): ?BiometricDevice
    {
        $device = BiometricDevice::findBySerial((string) $request->query('SN', ''));
        $device?->forceFill(['last_seen_at' => now()])->save();

        return $device;
    }

    /** Read the device's time in the library's own time zone, then record it. A time we cannot read is skipped. */
    protected function punch(BiometricDevice $device, string $pin, ?string $time): ?BiometricPunch
    {
        $tz = $device->library?->timezone ?: config('app.timezone');
        try {
            $at = $time ? Carbon::parse($time, $tz) : Carbon::now($tz);
        } catch (\Throwable) {
            return null;
        }

        return app(RecordBiometricPunch::class)($device, $pin, $at);
    }

    protected function text(string $body, int $status = 200): Response
    {
        return response($body."\n", $status)->header('Content-Type', 'text/plain');
    }
}
