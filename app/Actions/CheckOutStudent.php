<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CheckOutStudent
{
    /** Staff checking a student out by hand. */
    public function __invoke(Attendance $attendance): Attendance
    {
        abort_unless(auth()->user()?->can('attendance.record'), 403);

        return $this->close($attendance, now(), auth()->id());
    }

    /** A fingerprint / face / card machine reported the student leaving. */
    public function fromDevice(Attendance $attendance, CarbonInterface $at): Attendance
    {
        return $this->close($attendance, $at, null);
    }

    protected function close(Attendance $attendance, CarbonInterface $at, ?int $by): Attendance
    {
        return DB::transaction(function () use ($attendance, $at, $by) {
            $attendance = Attendance::query()->whereKey($attendance->getKey())->lockForUpdate()->firstOrFail();

            if (! $attendance->isOpen()) {
                throw new BusinessRuleException('This student has already checked out.');
            }
            // A stale record (missed check-out from an earlier day) needs an authorised correction instead.
            if ($attendance->isStale()) {
                throw new BusinessRuleException('This check-in is from an earlier day. Ask someone with correction rights to close it.');
            }
            if ($at->lt($attendance->checked_in_at)) {
                throw new BusinessRuleException('The check-out time is before the check-in.');
            }

            $attendance->forceFill([
                'checked_out_at' => $at,
                'checked_out_by' => $by,
                'active_student_id' => null,
            ])->save();

            return $attendance;
        });
    }
}
