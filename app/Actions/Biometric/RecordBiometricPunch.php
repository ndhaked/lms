<?php

namespace App\Actions\Biometric;

use App\Actions\CheckInStudent;
use App\Actions\CheckOutStudent;
use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Models\Student;
use App\Support\Entitlements;
use App\Support\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * One punch from a machine: find the student by the ID enrolled on it, then check them in, or out if they are inside.
 * Machines retransmit and buffer, so the same punch is stored once, repeats within a short window are ignored, and
 * punches from an earlier day are logged but never change today's attendance.
 */
class RecordBiometricPunch
{
    /** A second punch this soon after the last one is a double tap, not a check-out. */
    public const DEBOUNCE_SECONDS = 120;

    public function __invoke(BiometricDevice $device, string $pin, CarbonInterface $at): BiometricPunch
    {
        return Tenancy::run($device->library, function () use ($device, $pin, $at) {
            $pin = mb_substr(trim($pin), 0, 40);

            $same = fn () => BiometricPunch::where(['biometric_device_id' => $device->id, 'pin' => $pin, 'punched_at' => $at->toDateTimeString()])->first();
            if ($existing = $same()) {
                return $existing;
            }

            [$result, $message, $student] = $this->apply($device, $pin, $at);

            try {
                return BiometricPunch::create([
                    'biometric_device_id' => $device->id, 'pin' => $pin, 'punched_at' => $at,
                    'student_id' => $student?->id, 'result' => $result, 'message' => $message,
                ]);
            } catch (UniqueConstraintViolationException) {
                return $same();
            }
        });
    }

    /** @return array{0: string, 1: string, 2: ?Student} */
    protected function apply(BiometricDevice $device, string $pin, CarbonInterface $at): array
    {
        if (! $device->is_active) {
            return ['rejected', 'This device is switched off in the library settings.', null];
        }
        if (! Entitlements::hasModule('attendance') || ! Entitlements::allows() || Entitlements::readOnly()) {
            return ['rejected', "Attendance is not available on this library's plan right now.", null];
        }

        $student = $pin === '' ? null : Student::where('biometric_id', $pin)->first();
        if (! $student) {
            return ['unknown', "No student has biometric ID {$pin}. Add it on the student's page.", null];
        }

        if ($at->lt(today())) {
            return ['ignored', 'Punch is from an earlier day, so attendance was not changed.', $student];
        }
        if ($at->gt(now()->addMinutes(10))) {
            return ['rejected', 'The device clock is ahead of the server. Check the date and time on the device.', $student];
        }

        $last = Attendance::where('student_id', $student->id)->latest('id')->first();
        $lastAt = $last ? ($last->checked_out_at ?? $last->checked_in_at) : null;
        if ($lastAt && abs($at->diffInSeconds($lastAt)) < self::DEBOUNCE_SECONDS) {
            return ['ignored', 'Repeated punch within two minutes.', $student];
        }

        try {
            $open = Attendance::where('student_id', $student->id)->whereNull('checked_out_at')->first();
            if ($open) {
                app(CheckOutStudent::class)->fromDevice($open, $at);

                return ['checked_out', "{$student->name} checked out.", $student];
            }
            app(CheckInStudent::class)->fromDevice($student, $at, $device->id);

            return ['checked_in', "{$student->name} checked in.", $student];
        } catch (BusinessRuleException $e) {
            return ['rejected', $e->getMessage(), $student];
        }
    }
}
