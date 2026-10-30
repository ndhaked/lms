<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AssignMembership;
use App\Http\Resources\Api\V1\Present;
use App\Models\AuditLog;
use App\Models\MembershipPlan;
use App\Models\RenewalRequest;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Membership plans (what students can buy) and memberships (what they bought). Same rules as the web pages. */
class MembershipController extends Controller
{
    // ---------- plans ----------

    public function plans(Request $request): JsonResponse
    {
        $this->authorizePermission('memberships.view|seats.allocate');

        return $this->ok(MembershipPlan::query()->when($request->boolean('active_only'), fn ($q) => $q->where('status', 'active'))
            ->withCount(['memberships as active_count' => fn ($q) => $q->current()])
            ->orderBy('name')->get()->map(fn ($p) => $this->plan($p))->all());
    }

    public function storePlan(Request $request): JsonResponse
    {
        $this->authorizePermission('plans.manage');
        $this->writable();

        return $this->message('Plan saved.', $this->plan(MembershipPlan::create($this->planData($request, null))), 201);
    }

    public function updatePlan(Request $request, int $plan): JsonResponse
    {
        $this->authorizePermission('plans.manage');
        $this->writable();
        $p = MembershipPlan::findOrFail($plan);
        $p->update($this->planData($request, $p->id));

        return $this->message('Plan saved.', $this->plan($p));
    }

    protected function planData(Request $request, ?int $id): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('membership_plans', 'name')->where('library_id', Tenancy::currentId())->ignore($id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_unit' => ['required', Rule::in(array_keys(MembershipPlan::DURATIONS))],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'registration_fee' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'seat_type' => ['required', Rule::in(array_keys(MembershipPlan::SEAT_TYPES))],
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:90'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        return array_map(fn ($v) => $v === '' ? null : $v, $data);
    }

    protected function plan(MembershipPlan $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'description' => $p->description,
            'duration_unit' => $p->duration_unit, 'duration_label' => MembershipPlan::DURATIONS[$p->duration_unit] ?? $p->duration_unit,
            'price' => Present::money($p->price), 'registration_fee' => Present::money($p->registration_fee),
            'seat_type' => $p->seat_type, 'shift_start' => $p->shift_start ? substr($p->shift_start, 0, 5) : null, 'shift_end' => $p->shift_end ? substr($p->shift_end, 0, 5) : null,
            'grace_days' => (int) $p->grace_days, 'status' => $p->status, 'active_members' => isset($p->active_count) ? (int) $p->active_count : null,
        ];
    }

    // ---------- memberships ----------

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('memberships.view');
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(['active', 'upcoming', 'expired', 'expiring', 'suspended', 'cancelled'])],
            'plan_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
        ]);
        $q = trim((string) ($f['q'] ?? ''));
        $state = $f['state'] ?? null;

        $page = StudentMembership::query()->with(['student:id,name,phone,student_code', 'plan:id,name'])
            ->when($q !== '', fn ($w) => $w->whereHas('student', fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('student_code', 'like', "%{$q}%")))
            ->when($f['plan_id'] ?? null, fn ($w, $p) => $w->where('membership_plan_id', $p))
            ->when($f['student_id'] ?? null, fn ($w, $s) => $w->where('student_id', $s))
            ->when($state === 'active', fn ($w) => $w->current())
            ->when($state === 'upcoming', fn ($w) => $w->where('status', 'active')->whereDate('starts_on', '>', today()))
            ->when($state === 'expired', fn ($w) => $w->where('status', 'active')->whereDate('ends_on', '<', today()))
            ->when($state === 'expiring', fn ($w) => $w->expiringWithin((int) LibrarySettings::get('renewal_window_days')))
            ->when(in_array($state, ['suspended', 'cancelled'], true), fn ($w) => $w->where('status', $state))
            ->orderByDesc('ends_on')->paginate($this->perPage());

        return $this->paged($page, fn ($m) => Present::membership($m) + ['student' => ['id' => $m->student?->id, 'name' => $m->student?->name, 'code' => $m->student?->student_code, 'phone' => $m->student?->phone]]);
    }

    public function show(int $membership): JsonResponse
    {
        $this->authorizePermission('memberships.view');
        $m = StudentMembership::with(['student:id,name,student_code', 'plan:id,name', 'allocations.payment'])->findOrFail($membership);

        return $this->ok(Present::membership($m) + [
            'student' => ['id' => $m->student?->id, 'name' => $m->student?->name, 'code' => $m->student?->student_code],
            'notes' => $m->notes,
            'payments' => $this->can('payments.view') ? $m->allocations->map(fn ($a) => ['payment_id' => $a->payment_id, 'receipt_no' => $a->payment?->receipt_no, 'amount' => Present::money($a->amount), 'paid_on' => Present::d($a->payment?->paid_on), 'reversed' => (bool) $a->payment?->isReversed()])->values()->all() : null,
        ]);
    }

    /** Assign (sell) a membership. A discount needs memberships.discount. Optionally closes a student's renewal request. */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('memberships.manage');
        $this->writable();
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'plan_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'renewal_request_id' => ['nullable', 'integer'],
        ], ['student_id.required' => 'Choose a student.', 'plan_id.required' => 'Choose a plan.']);

        $m = app(AssignMembership::class)(
            Student::findOrFail($data['student_id']),
            MembershipPlan::findOrFail($data['plan_id']),
            Carbon::parse($data['starts_on']),
            (float) ($data['discount'] ?? 0),
            $data['notes'] ?? null,
        );

        if (! empty($data['renewal_request_id'])) {
            RenewalRequest::whereKey($data['renewal_request_id'])->where('status', 'pending')
                ->update(['status' => 'fulfilled', 'handled_by' => auth()->id(), 'handled_at' => now()]);
        }

        return $this->message('Membership assigned.', Present::membership($m->load('plan')), 201);
    }

    /** Renew on the same plan: continues from the old end date, or starts today (library setting). */
    public function renew(int $membership): JsonResponse
    {
        $this->authorizePermission('memberships.manage');
        $this->writable();
        $m = StudentMembership::with(['student', 'plan'])->findOrFail($membership);
        $start = LibrarySettings::get('renewal_starts_after_expiry') ? max(today(), $m->ends_on->copy()->addDay()) : today();
        $new = app(AssignMembership::class)($m->student, $m->plan, $start);

        return $this->message('Membership renewed.', Present::membership($new->load('plan')), 201);
    }

    /** Dates and price are fixed once sold; only the status (active / suspended = frozen / cancelled) and notes change. */
    public function update(Request $request, int $membership): JsonResponse
    {
        $this->authorizePermission('memberships.manage');
        $this->writable();
        $data = $request->validate(['status' => ['required', 'in:active,suspended,cancelled'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $m = StudentMembership::with('plan')->findOrFail($membership);
        $from = $m->status;
        $m->update(['status' => $data['status'], 'notes' => $data['notes'] ?? null]);
        if ($from !== $m->status) {
            AuditLog::record('membership.status_changed', $m, ['from' => $from, 'to' => $m->status]);
        }

        return $this->message('Membership updated.', Present::membership($m));
    }

    // ---------- renewal requests sent by students ----------

    public function requests(): JsonResponse
    {
        $this->authorizePermission('memberships.manage');

        return $this->ok(RenewalRequest::with(['student:id,name,student_code,phone', 'plan:id,name'])->where('status', 'pending')->latest('id')->limit(50)->get()
            ->map(fn ($r) => ['id' => $r->id, 'student' => ['id' => $r->student?->id, 'name' => $r->student?->name, 'code' => $r->student?->student_code],
                'plan' => $r->plan ? ['id' => $r->plan->id, 'name' => $r->plan->name] : null, 'message' => $r->message, 'created_at' => Present::t($r->created_at)])->all());
    }

    public function dismissRequest(int $request): JsonResponse
    {
        $this->authorizePermission('memberships.manage');
        $this->writable();
        $r = RenewalRequest::where('status', 'pending')->findOrFail($request);
        $r->forceFill(['status' => 'dismissed', 'handled_by' => auth()->id(), 'handled_at' => now()])->save();

        return $this->message('Request dismissed.');
    }
}
