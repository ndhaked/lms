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
    public function __invoke(Seat $seat, Student $student, string $shift = 'fullday'): SeatAllocation
    {
        try {
            return DB::transaction(function () use ($seat, $student, $shift) {
                // Lock both rows so two staff members cannot allocate the same seat at the same moment.
                $seat = Seat::query()->whereKey($seat->getKey())->lockForUpdate()->firstOrFail();
                $student = Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

                if (! isset(SeatAllocation::SHIFTS[$shift])) {
                    throw new BusinessRuleException('Choose a valid shift.');
                }
                if (! in_array($seat->status, [Seat::AVAILABLE, Seat::OCCUPIED], true)) {
                    throw new BusinessRuleException("Seat {$seat->code} is not available (status: {$seat->status}).");
                }
                // Occupied only means someone has a shift; the seat is still free for the other shifts.
                $clash = SeatAllocation::query()->where('seat_id', $seat->id)->whereNull('released_at')
                    ->when($shift !== 'fullday', fn ($q) => $q->whereIn('shift', ['fullday', $shift]))->exists();
                if ($clash) {
                    throw new BusinessRuleException("Seat {$seat->code} is not available for ".(SeatAllocation::SHIFTS[$shift]).'.');
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
                    'shift' => $shift,
                    'active_slot' => "{$seat->id}-{$shift}",
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
