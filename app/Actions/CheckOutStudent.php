<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use Illuminate\Support\Facades\DB;

class CheckOutStudent
{
    public function __invoke(Attendance $attendance): Attendance
    {
        abort_unless(auth()->user()?->can('attendance.record'), 403);

        return DB::transaction(function () use ($attendance) {
            $attendance = Attendance::query()->whereKey($attendance->getKey())->lockForUpdate()->firstOrFail();

            if (! $attendance->isOpen()) {
                throw new BusinessRuleException('This student has already checked out.');
            }
            // A stale record (missed check-out from an earlier day) needs an authorised correction instead.
            if ($attendance->isStale()) {
                throw new BusinessRuleException('This check-in is from an earlier day. Ask someone with correction rights to close it.');
            }

            $attendance->forceFill([
                'checked_out_at' => now(),
                'checked_out_by' => auth()->id(),
                'active_student_id' => null,
            ])->save();

            return $attendance;
        });
    }
}
