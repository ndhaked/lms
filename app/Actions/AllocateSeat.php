<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Seat;
use App\Models\SeatAllocation;
use App\Models\Student;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AllocateSeat
{
    public function __invoke(Seat $seat, Student $student): SeatAllocation
    {
        try {
            return DB::transaction(function () use ($seat, $student) {
                // Lock both rows so two staff members cannot allocate the same seat at the same moment.
                $seat = Seat::query()->whereKey($seat->getKey())->lockForUpdate()->firstOrFail();
                $student = Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

                if ($seat->status !== Seat::AVAILABLE) {
                    throw new BusinessRuleException("Seat {$seat->code} is not available (status: {$seat->status}).");
                }
                if ($student->status !== Student::ACTIVE) {
                    throw new BusinessRuleException("{$student->name} is {$student->status} and cannot be allocated a seat.");
                }
                if ($student->activeAllocation()->exists()) {
                    throw new BusinessRuleException("{$student->name} already has a seat. Release it first.");
                }

                $membership = $student->currentMembership();
                if (! $membership) {
                    throw new BusinessRuleException("{$student->name} has no active membership.");
                }

                $allocation = SeatAllocation::create([
                    'seat_id' => $seat->id,
                    'student_id' => $student->id,
                    'student_membership_id' => $membership->id,
                    'allocated_at' => now(),
                    'allocated_by' => auth()->id(),
                    'active_seat_id' => $seat->id,
                    'active_student_id' => $student->id,
                ]);

                $seat->update(['status' => Seat::OCCUPIED]);

                AuditLog::record('seat.allocated', $allocation, ['seat' => $seat->code, 'student' => $student->student_code]);

                return $allocation;
            });
        } catch (UniqueConstraintViolationException) {
            // Database-level backstop for a race that slipped past the lock.
            throw new BusinessRuleException('That seat or student was just allocated by someone else. Please refresh.');
        }
    }
}
