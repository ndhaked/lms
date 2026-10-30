<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use App\Models\Student;
use App\Support\LibrarySettings;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CheckInStudent
{
    /** Staff checking a student in by hand. */
    public function __invoke(Student $student): Attendance
    {
        abort_unless(auth()->user()?->can('attendance.record'), 403);

        return $this->record($student, now(), auth()->id(), 'manual', null);
    }

    /** A fingerprint / face / card machine reported the student. Same rules as by hand; no signed-in user. */
    public function fromDevice(Student $student, CarbonInterface $at, int $deviceId): Attendance
    {
        return $this->record($student, $at, null, 'biometric', $deviceId);
    }

    protected function record(Student $student, CarbonInterface $at, ?int $by, string $source, ?int $deviceId): Attendance
    {
        try {
            return DB::transaction(function () use ($student, $at, $by, $source, $deviceId) {
                $student = Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

                if ($student->status !== Student::ACTIVE) {
                    throw new BusinessRuleException("{$student->name} is {$student->status} and cannot check in.");
                }
                $membership = $student->currentMembership();
                if (! $membership && LibrarySettings::get('attendance_require_membership')) {
                    throw new BusinessRuleException("{$student->name} has no running membership.");
                }
                if (Attendance::where('student_id', $student->id)->whereNull('checked_out_at')->exists()) {
                    throw new BusinessRuleException("{$student->name} is already checked in.");
                }

                // Late = arriving after the plan's shift start plus the library's grace period.
                $shift = $membership?->plan?->shift_start;
                $late = $shift && $at->gt($at->copy()->startOfDay()->setTimeFromTimeString($shift)->addMinutes((int) LibrarySettings::get('attendance_grace_minutes')));

                return Attendance::create([
                    'student_id' => $student->id,
                    'is_late' => (bool) $late,
                    // Attendance and seat occupancy are separate facts: the seat is only recorded for reference.
                    'seat_id' => $student->activeAllocation?->seat_id,
                    'source' => $source,
                    'biometric_device_id' => $deviceId,
                    'checked_in_at' => $at,
                    'checked_in_by' => $by,
                    'active_student_id' => $student->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('That student was just checked in by someone else.');
        }
    }
}
