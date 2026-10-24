<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\AuditLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CorrectAttendance
{
    /**
     * Fixes check-in/check-out times (including closing a missed check-out). Only people with the
     * correction permission may do it, a reason is mandatory, and the before/after is kept.
     */
    public function __invoke(Attendance $attendance, CarbonInterface $checkedIn, ?CarbonInterface $checkedOut, string $reason): Attendance
    {
        abort_unless(auth()->user()?->can('attendance.correct'), 403);

        if (blank($reason)) {
            throw new BusinessRuleException('A reason is required for a correction.');
        }
        if ($checkedIn->isFuture() || ($checkedOut && $checkedOut->isFuture())) {
            throw new BusinessRuleException('Times cannot be in the future.');
        }
        if ($checkedOut && $checkedOut->lt($checkedIn)) {
            throw new BusinessRuleException('Check-out cannot be before check-in.');
        }

        return DB::transaction(function () use ($attendance, $checkedIn, $checkedOut, $reason) {
            $attendance = Attendance::query()->whereKey($attendance->getKey())->lockForUpdate()->firstOrFail();

            if (! $attendance->isOpen() && ! $checkedOut) {
                throw new BusinessRuleException('A completed visit cannot be reopened. Add the check-out time.');
            }

            $fmt = fn (?CarbonInterface $t) => $t?->toDateTimeString();
            $before = ['checked_in_at' => $fmt($attendance->checked_in_at), 'checked_out_at' => $fmt($attendance->checked_out_at)];
            $after = ['checked_in_at' => $fmt($checkedIn), 'checked_out_at' => $fmt($checkedOut)];

            $attendance->forceFill(['checked_in_at' => $checkedIn, 'checked_out_at' => $checkedOut]);
            if ($checkedOut) {
                $attendance->checked_out_by ??= auth()->id();
                $attendance->active_student_id = null; // the visit is over, the student may check in again
            }
            $attendance->save();

            AttendanceCorrection::create([
                'attendance_id' => $attendance->id,
                'user_id' => auth()->id(),
                'reason' => $reason,
                'before' => $before,
                'after' => $after,
            ]);
            AuditLog::record('attendance.corrected', $attendance, ['reason' => $reason, 'before' => $before, 'after' => $after]);

            return $attendance;
        });
    }
}
