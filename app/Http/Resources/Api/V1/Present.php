<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Attendance;
use App\Models\Payment;
use App\Models\Seat;
use App\Models\SeatAllocation;
use App\Models\Student;
use App\Models\StudentMembership;
use Illuminate\Support\Str;

/**
 * How each record looks in the mobile API, in one place, so every endpoint returns the same shape.
 * Dates are YYYY-MM-DD, times ISO 8601 in the library's time zone, money is a number in rupees.
 */
class Present
{
    public static function d($v): ?string
    {
        return $v?->toDateString();
    }

    public static function t($v): ?string
    {
        return $v?->toIso8601String();
    }

    public static function money($v): float
    {
        return round((float) $v, 2);
    }

    public static function photo(?string $path): ?string
    {
        return $path ? asset('storage/'.$path) : null;
    }

    /** Short student row for lists and pickers. */
    public static function studentRow(Student $s): array
    {
        $seat = $s->relationLoaded('activeAllocation') ? $s->activeAllocation : null;
        $last = ! $seat && $s->relationLoaded('latestAllocation') ? $s->latestAllocation : null;

        return [
            'id' => $s->id,
            'code' => $s->student_code,
            'number' => (int) Str::afterLast((string) $s->student_code, '-'),
            'name' => $s->name,
            'phone' => $s->phone,
            'status' => $s->status,
            'is_vip' => (bool) $s->is_vip,
            'photo_url' => self::photo($s->photo_path),
            'valid_until' => $s->valid_until ?? null,
            'seat' => $seat ? self::allocation($seat) : null,
            'last_seat' => $last ? self::allocation($last) : null,
        ];
    }

    /** Everything on the student's page. */
    public static function student(Student $s): array
    {
        return self::studentRow($s) + [
            'email' => $s->email,
            'address' => $s->address,
            'emergency_contact_name' => $s->emergency_contact_name,
            'emergency_contact_phone' => $s->emergency_contact_phone,
            'registered_on' => self::d($s->registered_on),
            'notes' => $s->notes,
            'member_type' => $s->member_type,
            'biometric_id' => $s->biometric_id,
            'has_login' => $s->user_id !== null,
        ];
    }

    public static function membership(StudentMembership $m): array
    {
        return [
            'id' => $m->id,
            'plan' => $m->relationLoaded('plan') ? ['id' => $m->plan?->id, 'name' => $m->plan?->name] : null,
            'student_id' => $m->student_id,
            'status' => $m->status,
            'state' => $m->effectiveStatus(),   // active | upcoming | expired | suspended | cancelled
            'starts_on' => self::d($m->starts_on),
            'ends_on' => self::d($m->ends_on),
            'price' => self::money($m->price),
            'registration_fee' => self::money($m->registration_fee),
            'discount' => self::money($m->discount),
            'total' => self::money($m->totalDue()),
            'paid' => self::money($m->paidAmount()),
            'balance' => self::money($m->outstanding()),
        ];
    }

    public static function allocation(SeatAllocation $a): array
    {
        return [
            'id' => $a->id,
            'seat_id' => $a->seat_id,
            'seat' => $a->relationLoaded('seat') ? $a->seat?->code : null,
            'hall' => $a->relationLoaded('seat') && $a->seat?->relationLoaded('hall') ? $a->seat->hall?->name : null,
            'shift' => $a->shift,
            'shift_label' => SeatAllocation::SHIFTS[$a->shift] ?? $a->shift,
            'allocated_at' => self::t($a->allocated_at),
            'released_at' => self::t($a->released_at),
            'release_reason' => $a->release_reason,
        ];
    }

    /**
     * A seat as it looks in one shift: its state there (available / occupied / reserved / unavailable / maintenance)
     * and who holds it. Needs `activeAllocations.student` loaded.
     */
    public static function seat(Seat $seat, string $shift): array
    {
        $holder = $seat->holderFor($shift);

        return [
            'id' => $seat->id,
            'code' => $seat->code,
            'hall_id' => $seat->study_hall_id,
            'hall' => $seat->relationLoaded('hall') ? $seat->hall?->name : null,
            'type' => $seat->type,
            'status' => $seat->status,          // stored status
            'state' => $seat->stateFor($shift),  // what it is in this shift
            'holder' => $holder ? [
                'allocation_id' => $holder->id, 'shift' => $holder->shift,
                'student' => $holder->student ? ['id' => $holder->student->id, 'name' => $holder->student->name, 'code' => $holder->student->student_code, 'number' => (int) Str::afterLast((string) $holder->student->student_code, '-')] : null,
            ] : null,
            'shifts' => $seat->activeAllocations->map(fn ($a) => ['shift' => $a->shift, 'student_id' => $a->student_id])->values()->all(),
            'notes' => $seat->notes,
        ];
    }

    public static function payment(Payment $p): array
    {
        return [
            'id' => $p->id,
            'receipt_no' => $p->receipt_no,
            'student' => $p->relationLoaded('student') && $p->student ? ['id' => $p->student->id, 'name' => $p->student->name, 'code' => $p->student->student_code] : null,
            'amount' => self::money($p->amount),
            'method' => $p->method,
            'method_label' => Payment::METHODS[$p->method] ?? $p->method,
            'reference' => $p->reference,
            'paid_on' => self::d($p->paid_on),
            'status' => $p->status,
            'refunded' => self::money($p->refundedAmount()),
            'notes' => $p->notes,
            'recorded_by' => $p->relationLoaded('recorder') ? $p->recorder?->name : null,
            'created_at' => self::t($p->created_at),
        ];
    }

    public static function attendance(Attendance $a): array
    {
        return [
            'id' => $a->id,
            'student' => $a->relationLoaded('student') && $a->student ? ['id' => $a->student->id, 'name' => $a->student->name, 'code' => $a->student->student_code] : null,
            'seat' => $a->relationLoaded('seat') ? $a->seat?->code : null,
            'checked_in_at' => self::t($a->checked_in_at),
            'checked_out_at' => self::t($a->checked_out_at),
            'minutes' => $a->minutes(),
            'is_late' => (bool) $a->is_late,
            'source' => $a->source ?? 'manual',
            'stale' => $a->isStale(),
            'checked_in_by' => $a->relationLoaded('checkedInBy') ? $a->checkedInBy?->name : null,
        ];
    }
}
