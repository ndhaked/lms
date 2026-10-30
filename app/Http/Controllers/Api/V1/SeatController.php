<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AllocateSeat;
use App\Actions\AssignMembership;
use App\Actions\ReleaseSeat;
use App\Http\Resources\Api\V1\Present;
use App\Models\MembershipPlan;
use App\Models\Seat;
use App\Models\SeatAllocation;
use App\Models\Student;
use App\Models\StudyHall;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Halls, the seat map by shift, and giving / taking back seats. A seat can be shared by students in different
 * shifts (morning, afternoon, evening, night); a full-day booking blocks every shift. Allocation is checked again
 * on the server under a row lock, so two phones can never give away the same seat and shift.
 */
class SeatController extends Controller
{
    // ---------- halls ----------

    public function halls(): JsonResponse
    {
        $this->authorizePermission('seats.view');

        return $this->ok(StudyHall::with('supervisor:id,name')->withCount(['seats', 'seats as occupied' => fn ($q) => $q->where('status', Seat::OCCUPIED)])->orderBy('name')->get()
            ->map(fn ($h) => $this->hall($h))->all());
    }

    public function storeHall(Request $request): JsonResponse
    {
        $this->authorizePermission('halls.manage');
        $this->writable();
        $hall = StudyHall::create($this->hallData($request, null));

        return $this->message('Hall saved.', $this->hall($hall->loadCount('seats')), 201);
    }

    public function updateHall(Request $request, int $hall): JsonResponse
    {
        $this->authorizePermission('halls.manage');
        $this->writable();
        $h = StudyHall::findOrFail($hall);
        $h->update($this->hallData($request, $h->id));

        return $this->message('Hall saved.', $this->hall($h->loadCount('seats')));
    }

    protected function hallData(Request $request, ?int $id): array
    {
        $staff = $this->library()->users()->wherePivot('is_active', true)->pluck('users.id')->all();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('study_halls', 'name')->where('library_id', Tenancy::currentId())->ignore($id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'supervisor_id' => ['nullable', 'integer', Rule::in($staff)],
            'opening_time' => ['nullable', 'date_format:H:i'],
            'closing_time' => ['nullable', 'date_format:H:i', 'after:opening_time'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        return array_map(fn ($v) => $v === '' ? null : $v, $data);
    }

    protected function hall(StudyHall $h): array
    {
        return [
            'id' => $h->id, 'name' => $h->name, 'description' => $h->description, 'capacity' => $h->capacity,
            'seats' => (int) ($h->seats_count ?? 0), 'occupied' => (int) ($h->occupied ?? 0),
            'supervisor' => $h->relationLoaded('supervisor') && $h->supervisor ? ['id' => $h->supervisor->id, 'name' => $h->supervisor->name] : null,
            'opening_time' => $h->opening_time ? substr($h->opening_time, 0, 5) : null, 'closing_time' => $h->closing_time ? substr($h->closing_time, 0, 5) : null,
            'status' => $h->status,
        ];
    }

    // ---------- seat map ----------

    /** All seats as they are in one shift, with the counts for the All / Allotted / Available cards. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('seats.view');
        $f = $request->validate([
            'shift' => ['nullable', Rule::in(array_keys(SeatAllocation::SHIFTS))],
            'hall_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(array_keys(Seat::STATUSES))],
            'type' => ['nullable', Rule::in(array_keys(Seat::TYPES))],
            'q' => ['nullable', 'string', 'max:60'],
        ]);
        $shift = $f['shift'] ?? 'morning';
        $q = trim((string) ($f['q'] ?? ''));

        $seats = Seat::with(['hall:id,name', 'activeAllocations.student:id,name,student_code'])
            ->when($f['hall_id'] ?? null, fn ($w, $h) => $w->where('study_hall_id', $h))
            ->when($f['type'] ?? null, fn ($w, $t) => $w->where('type', $t))
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('code', 'like', "%{$q}%")
                ->orWhereHas('activeAllocations.student', fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('student_code', 'like', "%{$q}%"))))
            ->orderBy('study_hall_id')->orderBy('code')->limit(1000)->get();

        $rows = $seats->map(fn ($s) => Present::seat($s, $shift));
        if ($status = $f['status'] ?? null) {
            $rows = $rows->where('state', $status);
        }
        $all = Seat::with('activeAllocations')->get(['id', 'status']);
        $states = $all->countBy(fn ($s) => $s->stateFor($shift));

        return $this->ok([
            'shift' => $shift,
            'shifts' => collect(SeatAllocation::SHIFTS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'summary' => ['all' => $all->count(), 'allotted' => (int) ($states[Seat::OCCUPIED] ?? 0), 'available' => (int) ($states[Seat::AVAILABLE] ?? 0),
                'reserved' => (int) ($states[Seat::RESERVED] ?? 0), 'unavailable' => (int) ($states[Seat::UNAVAILABLE] ?? 0), 'maintenance' => (int) ($states[Seat::MAINTENANCE] ?? 0)],
            'halls' => StudyHall::orderBy('name')->get(['id', 'name'])->map(fn ($h) => ['id' => $h->id, 'name' => $h->name])->all(),
            'seats' => $rows->values()->all(),
        ]);
    }

    public function show(Request $request, int $seat): JsonResponse
    {
        $this->authorizePermission('seats.view');
        $s = Seat::with(['hall:id,name', 'activeAllocations.student:id,name,student_code', 'allocations' => fn ($q) => $q->with('student:id,name,student_code')->latest('allocated_at')->limit(20)])->findOrFail($seat);
        $shift = in_array($request->query('shift'), array_keys(SeatAllocation::SHIFTS), true) ? $request->query('shift') : 'morning';

        return $this->ok(Present::seat($s, $shift) + [
            'history' => $s->allocations->map(fn ($a) => Present::allocation($a) + ['student' => $a->student ? ['id' => $a->student->id, 'name' => $a->student->name, 'code' => $a->student->student_code] : null])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('seats.manage');
        $this->writable();
        $data = $this->seatData($request, null);
        Entitlements::assertCanAdd('seats');
        $seat = Seat::create($data);

        return $this->message('Seat saved.', Present::seat($seat->load(['hall', 'activeAllocations.student']), 'morning'), 201);
    }

    public function update(Request $request, int $seat): JsonResponse
    {
        $this->authorizePermission('seats.manage');
        $this->writable();
        $s = Seat::findOrFail($seat);
        $data = $this->seatData($request, $s->id);
        // occupancy is controlled by allocation only: an occupied seat keeps that status
        if ($s->status === Seat::OCCUPIED) {
            $data['status'] = Seat::OCCUPIED;
        }
        $s->update($data);

        return $this->message('Seat saved.', Present::seat($s->load(['hall', 'activeAllocations.student']), 'morning'));
    }

    protected function seatData(Request $request, ?int $id): array
    {
        $data = $request->validate([
            'hall_id' => ['required', Rule::exists('study_halls', 'id')->where('library_id', Tenancy::currentId())],
            'code' => ['required', 'string', 'max:30', Rule::unique('seats', 'code')->where('library_id', Tenancy::currentId())->ignore($id)],
            'type' => ['required', Rule::in(array_keys(Seat::TYPES))],
            'status' => ['nullable', Rule::in(array_diff(array_keys(Seat::STATUSES), [Seat::OCCUPIED]))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['hall_id' => 'hall', 'code' => 'seat number']);

        return ['study_hall_id' => $data['hall_id'], 'code' => trim($data['code']), 'type' => $data['type'], 'status' => $data['status'] ?? Seat::AVAILABLE, 'notes' => $data['notes'] ?? null];
    }

    /** Make many seats at once: prefix + numbers (A-01, A-02 ...). Numbers that exist are skipped. */
    public function generate(Request $request): JsonResponse
    {
        $this->authorizePermission('seats.manage');
        $this->writable();
        $data = $request->validate([
            'hall_id' => ['required', Rule::exists('study_halls', 'id')->where('library_id', Tenancy::currentId())],
            'prefix' => ['nullable', 'string', 'max:10'],
            'start' => ['required', 'integer', 'min:1', 'max:99999'],
            'count' => ['required', 'integer', 'min:1', 'max:500'],
            'type' => ['required', Rule::in(array_keys(Seat::TYPES))],
        ]);
        $prefix = $data['prefix'] ?? (string) LibrarySettings::get('seat_prefix');
        $pad = (int) LibrarySettings::get('seat_padding');

        $codes = [];
        for ($n = (int) $data['start']; $n < (int) $data['start'] + (int) $data['count']; $n++) {
            $code = $prefix.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
            if (! Seat::where('code', $code)->exists()) {
                $codes[] = $code;
            }
        }
        Entitlements::assertCanAdd('seats', count($codes));

        DB::transaction(function () use ($codes, $data) {
            foreach ($codes as $code) {
                Seat::create(['study_hall_id' => $data['hall_id'], 'code' => $code, 'type' => $data['type']]);
            }
        });

        return $this->message(count($codes).' seat(s) created'.(count($codes) < (int) $data['count'] ? ' (existing numbers were skipped).' : '.'), ['created' => $codes], 201);
    }

    // ---------- allocate / release ----------

    /** Students who can take a seat now: active, with a running membership and no seat. */
    public function eligible(Request $request): JsonResponse
    {
        $this->authorizePermission('seats.allocate');
        $q = trim((string) $request->query('q', ''));

        return $this->ok(Student::query()->where('status', Student::ACTIVE)->whereDoesntHave('activeAllocation')
            ->whereHas('memberships', fn ($m) => $m->current())
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('student_code', 'like', "%{$q}%")))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'phone', 'student_code'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'phone' => $s->phone, 'code' => $s->student_code])->all());
    }

    public function allocate(Request $request, int $seat): JsonResponse
    {
        $this->authorizePermission('seats.allocate');
        $this->writable();
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'shift' => ['required', Rule::in(array_keys(SeatAllocation::SHIFTS))],
        ]);
        $s = Seat::findOrFail($seat);
        $allocation = app(AllocateSeat::class)($s, Student::findOrFail($data['student_id']), $data['shift']);

        return $this->message("Seat {$s->code} allocated for ".SeatAllocation::SHIFTS[$data['shift']].'.', Present::allocation($allocation->load('seat.hall')), 201);
    }

    /** Free a seat for one shift (the holder of that shift; a full-day holder covers every shift). */
    public function release(Request $request, int $seat): JsonResponse
    {
        $this->authorizePermission('seats.allocate');
        $this->writable();
        $data = $request->validate(['shift' => ['nullable', Rule::in(array_keys(SeatAllocation::SHIFTS))], 'allocation_id' => ['nullable', 'integer']]);
        $s = Seat::with('activeAllocations')->findOrFail($seat);

        $holder = ! empty($data['allocation_id'])
            ? $s->activeAllocations->firstWhere('id', (int) $data['allocation_id'])
            : $s->holderFor($data['shift'] ?? 'fullday');
        abort_unless($holder, 404, 'Nobody holds this seat in that shift.');

        app(ReleaseSeat::class)($holder);

        return $this->message("Seat {$s->code} released.");
    }

    /**
     * Seat selection for one student (after registering, or "Allot seat"): gives the seat, moves them from their old one,
     * and starts a plan first when they have no running membership. All or nothing.
     */
    public function seatStudent(Request $request, int $student): JsonResponse
    {
        $this->authorizePermission('seats.allocate');
        $this->writable();
        $st = Student::with('activeAllocation.seat')->findOrFail($student);
        abort_unless($st->status === Student::ACTIVE, 422, "{$st->name} is not active.");

        $needsPlan = $st->currentMembership() === null;
        if ($needsPlan) {
            $this->authorizePermission('memberships.manage');
        }
        $data = $request->validate([
            'seat_id' => ['required', 'integer'],
            'shift' => ['required', Rule::in(array_keys(SeatAllocation::SHIFTS))],
            'plan_id' => [$needsPlan ? 'required' : 'nullable', 'integer'],
            'starts_on' => [$needsPlan ? 'required' : 'nullable', 'date'],
        ], ['plan_id.required' => 'Choose the membership plan the seat goes with.']);

        $seat = Seat::findOrFail($data['seat_id']);
        $plan = $needsPlan ? MembershipPlan::where('status', 'active')->findOrFail((int) $data['plan_id']) : null;
        $moved = $st->activeAllocation?->seat?->code;

        $allocation = DB::transaction(function () use ($st, $plan, $seat, $data, $needsPlan) {
            if ($old = $st->activeAllocation) {
                app(ReleaseSeat::class)($old, 'seat_changed');
            }
            if ($needsPlan) {
                app(AssignMembership::class)($st, $plan, Carbon::parse($data['starts_on']));
            }

            return app(AllocateSeat::class)($seat, $st->fresh(), $data['shift']);
        });

        $msg = $moved ? "{$st->name} moved from seat {$moved} to {$seat->code}." : "{$st->name} now has seat {$seat->code}".($plan ? " on the {$plan->name} plan." : '.');

        return $this->message($msg, Present::allocation($allocation->load('seat.hall')), 201);
    }

    /** Take the student's seat back. The history keeps it. */
    public function unseatStudent(int $student): JsonResponse
    {
        $this->authorizePermission('seats.allocate');
        $this->writable();
        $st = Student::with('activeAllocation.seat')->findOrFail($student);
        abort_unless($st->activeAllocation, 404, "{$st->name} has no seat.");
        $code = $st->activeAllocation->seat?->code;
        app(ReleaseSeat::class)($st->activeAllocation, 'seat_removed');

        return $this->message("Seat {$code} was taken back from {$st->name}.");
    }
}
