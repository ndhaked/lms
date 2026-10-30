<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CheckInStudent;
use App\Actions\CheckOutStudent;
use App\Actions\CorrectAttendance;
use App\Http\Resources\Api\V1\Present;
use App\Models\Attendance;
use App\Models\Student;
use App\Support\DigitalId;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Desk attendance (same as the web): who is inside, check in, check out, history, corrections.
 * Plus the scanner: staff scan the student's digital ID QR and the student is checked in, or out if inside.
 * Visits made by fingerprint machines appear here too (source = biometric).
 */
class AttendanceController extends Controller
{
    public function today(): JsonResponse
    {
        $this->requireModule('attendance');
        $this->authorizePermission('attendance.view');
        $inside = Attendance::with(['student:id,name,student_code', 'seat:id,code'])->whereNull('checked_out_at')->orderBy('checked_in_at')->get();

        return $this->ok([
            'inside' => $inside->map(fn ($a) => Present::attendance($a))->values()->all(),
            'summary' => [
                'inside' => $inside->count(),
                'visits_today' => Attendance::whereDate('checked_in_at', today())->count(),
                'missed_checkouts' => $inside->filter->isStale()->count(),
            ],
            'working_day' => LibrarySettings::isWorkingDay(today()),
            'actions' => ['record' => $this->can('attendance.record'), 'correct' => $this->can('attendance.correct')],
        ]);
    }

    /** Students who may check in now: active, running membership, not inside. */
    public function candidates(Request $request): JsonResponse
    {
        $this->requireModule('attendance');
        $this->authorizePermission('attendance.record');
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->ok([]);
        }

        return $this->ok(Student::query()->where('status', Student::ACTIVE)->whereHas('memberships', fn ($m) => $m->current())
            ->whereNotIn('id', Attendance::whereNull('checked_out_at')->select('student_id'))
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('student_code', 'like', "%{$q}%"))
            ->with('activeAllocation.seat')->orderBy('name')->limit(10)->get()
            ->map(fn ($s) => Present::studentRow($s))->all());
    }

    public function history(Request $request): JsonResponse
    {
        $this->requireModule('attendance');
        $this->authorizePermission('attendance.view');
        $f = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'q' => ['nullable', 'string', 'max:100'], 'student_id' => ['nullable', 'integer'], 'source' => ['nullable', 'in:manual,biometric']]);
        $q = trim((string) ($f['q'] ?? ''));

        $page = Attendance::with(['student:id,name,student_code', 'seat:id,code', 'checkedInBy:id,name'])
            ->when($f['date'] ?? (empty($f['student_id']) ? today()->toDateString() : null), fn ($w, $d) => $w->whereDate('checked_in_at', $d))
            ->when($f['student_id'] ?? null, fn ($w, $s) => $w->where('student_id', $s))
            ->when($f['source'] ?? null, fn ($w, $s) => $w->where('source', $s))
            ->when($q !== '', fn ($w) => $w->whereHas('student', fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('student_code', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
            ->latest('checked_in_at')->paginate($this->perPage());

        return $this->paged($page, fn ($a) => Present::attendance($a));
    }

    public function checkIn(Request $request): JsonResponse
    {
        $this->requireModule('attendance');
        $this->authorizePermission('attendance.record');
        $this->writable();
        $data = $request->validate(['student_id' => ['required', 'integer']]);
        $a = app(CheckInStudent::class)(Student::findOrFail($data['student_id']));

        return $this->message("{$a->student->name} checked in.", Present::attendance($a->load(['student', 'seat'])), 201);
    }

    public function checkOut(int $attendance): JsonResponse
    {
        $this->requireModule('attendance');
        $this->authorizePermission('attendance.record');
        $this->writable();
        $a = app(CheckOutStudent::class)(Attendance::findOrFail($attendance));

        return $this->message("{$a->student->name} checked out.", Present::attendance($a->load(['student', 'seat'])));
    }

    /** Fix a visit's times (e.g. a missed check-out). The change and its reason are logged. */
    public function correct(Request $request, int $attendance): JsonResponse
    {
        $this->requireModule('attendance');
        $this->authorizePermission('attendance.correct');
        $this->writable();
        $data = $request->validate(['checked_in_at' => ['required', 'date'], 'checked_out_at' => ['nullable', 'date'], 'reason' => ['required', 'string', 'max:500']]);
        $a = app(CorrectAttendance::class)(Attendance::findOrFail($attendance), Carbon::parse($data['checked_in_at']),
            ! empty($data['checked_out_at']) ? Carbon::parse($data['checked_out_at']) : null, $data['reason']);

        return $this->message('Attendance corrected and logged.', Present::attendance($a->load(['student', 'seat'])));
    }

    /**
     * Scanner: the student's digital ID QR (60 seconds valid). With mode=auto the student is checked in,
     * or out when already inside; mode=lookup only says who it is (e.g. before issuing books).
     */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:500'], 'mode' => ['nullable', 'in:auto,lookup']]);
        $id = DigitalId::studentId($data['token'], Tenancy::currentId());
        abort_unless($id, 422, 'This QR is not valid here or has expired. Ask the student to open their ID again.');
        $student = Student::with('activeAllocation.seat')->findOrFail($id);

        if (($data['mode'] ?? 'auto') === 'lookup') {
            $this->authorizePermission('students.view|attendance.record|circulation.issue');

            return $this->ok(['action' => 'lookup', 'student' => Present::studentRow($student)]);
        }

        $this->requireModule('attendance');
        $this->authorizePermission('attendance.record');
        $this->writable();
        $open = Attendance::where('student_id', $student->id)->whereNull('checked_out_at')->first();
        $a = $open ? app(CheckOutStudent::class)($open) : app(CheckInStudent::class)($student);

        return $this->message($student->name.($open ? ' checked out.' : ' checked in.'), ['action' => $open ? 'checked_out' : 'checked_in', 'student' => Present::studentRow($student), 'attendance' => Present::attendance($a->load('seat'))]);
    }
}
