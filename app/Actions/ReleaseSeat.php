<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Seat;
use App\Models\SeatAllocation;
use Illuminate\Support\Facades\DB;

class ReleaseSeat
{
    public function __invoke(SeatAllocation $allocation, string $reason = 'manual'): SeatAllocation
    {
        return DB::transaction(function () use ($allocation, $reason) {
            $allocation = SeatAllocation::query()->whereKey($allocation->getKey())->lockForUpdate()->firstOrFail();

            if (! $allocation->isActive()) {
                throw new BusinessRuleException('This allocation has already been released.');
            }

            $allocation->update([
                'released_at' => now(),
                'release_reason' => $reason,
                'released_by' => auth()->id(),
                'active_seat_id' => null,
                'active_slot' => null,
                'active_student_id' => null,
            ]);

            // A seat put into maintenance/unavailable meanwhile keeps that status.
            // ...and a seat another shift still holds stays occupied.
            if (! SeatAllocation::query()->where('seat_id', $allocation->seat_id)->whereNull('released_at')->exists()) {
                Seat::query()->whereKey($allocation->seat_id)->where('status', Seat::OCCUPIED)->update(['status' => Seat::AVAILABLE]);
            }

            AuditLog::record('seat.released', $allocation, ['reason' => $reason]);

            return $allocation;
        });
    }
}
