<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateStudentLogin;
use App\Actions\ReleaseSeat;
use App\Http\Resources\Api\V1\Present;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Support\CirculationRules;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Students: list with the web filters, register, view, edit, photo, status and student login. Same rules as the web forms. */
class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('students.view');
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([Student::ACTIVE, Student::SUSPENDED, Student::INACTIVE])],
            'view' => ['nullable', Rule::in(['vip', 'frozen'])],
            'membership' => ['nullable', Rule::in(['active', 'none', 'expiring'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $q = trim((string) ($f['q'] ?? ''));

        $page = Student::query()
            ->with(['activeAllocation.seat.hall', 'latestAllocation.seat.hall'])
            ->addSelect(['students.*', 'valid_until' => StudentMembership::query()->select('ends_on')
                ->whereColumn('student_memberships.student_id', 'students.id')->current()->orderByDesc('ends_on')->limit(1)])
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('student_code', 'like', "%{$q}%")))
            ->when($f['status'] ?? null, fn ($w, $s) => $w->where('status', $s))
            ->when($f['from'] ?? null, fn ($w, $d) => $w->whereDate('registered_on', '>=', $d))
            ->when($f['until'] ?? null, fn ($w, $d) => $w->whereDate('registered_on', '<=', $d))
            ->when(($f['view'] ?? null) === 'vip', fn ($w) => $w->where('is_vip', true))
            ->when(($f['view'] ?? null) === 'frozen', fn ($w) => $w->whereHas('memberships', fn ($m) => $m->where('status', 'suspended')))
            ->when(($f['membership'] ?? null) === 'active', fn ($w) => $w->whereHas('memberships', fn ($m) => $m->current()))
            ->when(($f['membership'] ?? null) === 'none', fn ($w) => $w->whereDoesntHave('memberships', fn ($m) => $m->current()))
            ->when(($f['membership'] ?? null) === 'expiring', fn ($w) => $w->whereHas('memberships', fn ($m) => $m->expiringWithin((int) LibrarySettings::get('renewal_window_days'))))
            ->latest('id')
            ->paginate($this->perPage());

        return $this->paged($page, fn (Student $s) => Present::studentRow($s), ['counts' => $this->counts()]);
    }

    /** Numbers for the Total / VIP / Blocked / Left / Frozen chips. */
    protected function counts(): array
    {
        return [
            'total' => Student::count(),
            'vip' => Student::where('is_vip', true)->count(),
            'blocked' => Student::where('status', Student::SUSPENDED)->count(),
            'left' => Student::where('status', Student::INACTIVE)->count(),
            'frozen' => Student::whereHas('memberships', fn ($m) => $m->where('status', 'suspended'))->count(),
        ];
    }

    public function show(int $student): JsonResponse
    {
        $this->authorizePermission('students.view');
        $s = Student::with(['memberships.plan', 'activeAllocation.seat.hall', 'latestAllocation.seat.hall', 'allocations.seat.hall'])->findOrFail($student);

        $data = [
            'student' => Present::student($s),
            'current_membership' => ($m = $s->currentMembership()) ? Present::membership($m->load('plan')) : null,
            'memberships' => $this->can('memberships.view') ? $s->memberships->sortByDesc('starts_on')->map(fn ($m) => Present::membership($m))->values()->all() : null,
            'balance' => $this->can('payments.view') ? Present::money($s->memberships->sum(fn ($m) => (float) $m->outstanding())) : null,
            'seat_history' => $s->allocations->sortByDesc('allocated_at')->map(fn ($a) => Present::allocation($a))->values()->all(),
            'actions' => [
                'edit' => $this->can('students.update'),
                'change_status' => $this->can('students.deactivate'),
                'assign_membership' => $this->can('memberships.manage'),
                'record_payment' => $this->can('payments.record'),
                'allot_seat' => $this->can('seats.allocate') && $s->status === Student::ACTIVE,
                'create_login' => $this->can('students.update') && ! $s->user_id && $s->status === Student::ACTIVE,
            ],
        ];
        if ($this->can('attendance.view') && Entitlements::hasModule('attendance')) {
            $data['recent_attendance'] = Attendance::with('seat')->where('student_id', $s->id)->latest('checked_in_at')->limit(10)->get()->map(fn ($a) => Present::attendance($a))->all();
        }
        if ($this->can('payments.view')) {
            $data['recent_payments'] = Payment::where('student_id', $s->id)->latest('id')->limit(10)->get()->map(fn ($p) => Present::payment($p))->all();
        }

        return $this->ok(array_filter($data, fn ($v) => $v !== null));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('students.create');
        $this->writable();
        $data = $this->validated($request, null);

        // Someone with the same name or email may already be registered: say so, unless the app confirms it is a different person.
        if (! $request->boolean('confirm_duplicate')) {
            $dupes = Student::query()
                ->where(fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower(trim($data['name']))])
                    ->when(! empty($data['email']), fn ($q) => $q->orWhere('email', $data['email'])))
                ->limit(5)->get(['id', 'name', 'phone', 'email', 'student_code', 'status']);
            if ($dupes->isNotEmpty()) {
                return response()->json([
                    'message' => 'This looks like someone already registered. Send confirm_duplicate=true if it is a different person.',
                    'code' => 'possible_duplicate',
                    'duplicates' => $dupes->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'phone' => $s->phone, 'code' => $s->student_code, 'status' => $s->status])->all(),
                ], 409);
            }
        }

        Entitlements::assertCanAdd('students');   // plan limit (422 with the reason)

        $photo = $request->file('photo');
        unset($data['photo']);
        $student = new Student($data + ['status' => Student::ACTIVE]);
        if ($photo) {
            $student->photo_path = $photo->store('students', 'public');
        }
        $student->save();

        return $this->message("{$student->name} registered as {$student->student_code}.", [
            'student' => Present::student($student->fresh()),
            'can_choose_seat' => $this->can('seats.allocate'),   // open the seat selection screen next
        ], 201);
    }

    public function update(Request $request, int $student): JsonResponse
    {
        $this->authorizePermission('students.update');
        $this->writable();
        $s = Student::findOrFail($student);
        $data = $this->validated($request, $s->id);
        unset($data['photo']);

        $s->fill($data);
        $changed = array_keys($s->getDirty());
        $s->save();
        if ($changed) {
            AuditLog::record('student.updated', $s, ['changed' => $changed]);
        }

        return $this->message('Student updated.', Present::student($s->fresh(['activeAllocation.seat.hall'])));
    }

    /** Replace the photograph (multipart, field "photo"). */
    public function photo(Request $request, int $student): JsonResponse
    {
        $this->authorizePermission('students.update');
        $this->writable();
        $s = Student::findOrFail($student);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $s->forceFill(['photo_path' => $request->file('photo')->store('students', 'public')])->save();

        return $this->message('Photo updated.', ['photo_url' => Present::photo($s->photo_path)]);
    }

    /** Active, blocked (suspended) or left (inactive). Leaving frees the seat; the history is kept. */
    public function status(Request $request, int $student): JsonResponse
    {
        $this->authorizePermission('students.deactivate');
        $this->writable();
        $data = $request->validate(['status' => ['required', Rule::in([Student::ACTIVE, Student::SUSPENDED, Student::INACTIVE])]]);
        $s = Student::with('activeAllocation')->findOrFail($student);

        DB::transaction(function () use ($s, $data) {
            if ($data['status'] === Student::INACTIVE && $s->activeAllocation) {
                app(ReleaseSeat::class)($s->activeAllocation, 'student_deactivated');
            }
            $s->update(['status' => $data['status']]);
            AuditLog::record('student.status_changed', $s, ['status' => $data['status']]);
        });

        return $this->message('Status changed.', Present::student($s->fresh(['activeAllocation.seat.hall', 'latestAllocation.seat.hall'])));
    }

    /** Invite the student to sign in (they get an email to choose a password). */
    public function createLogin(int $student): JsonResponse
    {
        $this->authorizePermission('students.update');
        $this->writable();
        $user = app(CreateStudentLogin::class)(Student::findOrFail($student));

        return $this->message($user->wasRecentlyCreated ? "Login created. A setup email was sent to {$user->email}." : 'Login linked to the existing account.');
    }

    /** Field rules, the same as the web register / edit form. */
    protected function validated(Request $request, ?int $id): array
    {
        $library = Tenancy::currentId();
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('students', 'phone')->where('library_id', $library)->ignore($id)],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'registered_on' => [$id ? 'sometimes' : 'nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'member_type' => ['nullable', Rule::in(array_keys(CirculationRules::memberTypes()))],
            'is_vip' => ['nullable', 'boolean'],
            'biometric_id' => ['nullable', 'string', 'max:40', Rule::unique('students', 'biometric_id')->where('library_id', $library)->ignore($id)],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
        $data = $request->validate($rules, [
            'phone.unique' => 'A student with this phone number is already registered.',
            'biometric_id.unique' => 'Another student already has this biometric / card ID.',
        ]);

        $data = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);
        if (! $id) {
            $data['registered_on'] ??= today()->toDateString();
            $data['member_type'] ??= 'student';
        }
        if (array_key_exists('is_vip', $data)) {
            $data['is_vip'] = (bool) $data['is_vip'];
        }

        return array_filter($data, fn ($v, $k) => $v !== null || in_array($k, ['email', 'address', 'emergency_contact_name', 'emergency_contact_phone', 'notes', 'biometric_id'], true), ARRAY_FILTER_USE_BOTH);
    }
}
