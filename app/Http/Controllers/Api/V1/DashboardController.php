<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\Fine;
use App\Models\LoanItem;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Seat;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Models\StudyHall;
use App\Support\AcademicYear;
use App\Support\Entitlements;
use App\Support\LibraryInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner / staff dashboard and insights, with the same numbers as the web dashboard.
 * Every block depends on the person's permissions and the plan's modules (a receptionist sees fewer tiles).
 * `?year=2025` picks an academic year for the lists; the tiles always describe today.
 */
class DashboardController extends Controller
{
    protected const STAFF = ['students.view', 'seats.view', 'memberships.view', 'attendance.view', 'payments.view', 'expenses.view', 'reports.operational', 'reports.financial', 'staff.manage', 'settings.manage', 'billing.view'];

    public function dashboard(Request $request): JsonResponse
    {
        abort_unless(collect(self::STAFF)->contains(fn ($p) => $this->can($p)), 403, 'Students use the student screens.');

        $start = AcademicYear::resolve($request->query('year'));
        [$from, $to] = AcademicYear::range($start);
        $range = [$from->toDateString(), $to->toDateString()];
        $i = LibraryInsights::all();
        $m = $i['members'];
        $mo = $i['money'];

        $tiles = [];
        $tile = function (string $key, string $label, $value, string $display, string $tone, string $screen) use (&$tiles) {
            $tiles[] = compact('key', 'label', 'value', 'display', 'tone', 'screen');
        };
        $short = fn ($v) => LibraryInsights::short($v);

        if ($this->can('students.view')) {
            $tile('live_members', 'Live members', $m['live'], (string) $m['live'], 'violet', 'students');
            $tile('total_members', 'Total members', $m['total'], (string) $m['total'], 'blue', 'students');
        }
        if ($this->can('memberships.view')) {
            $tile('expired_members', 'Expired members', $m['expired'], (string) $m['expired'], 'red', 'memberships');
            $tile('expiring_1_3', 'Expiring 1–3 days', $m['expiring']['1-3'], (string) $m['expiring']['1-3'], 'rose', 'memberships');
            $tile('expiring_4_7', 'Expiring 4–7 days', $m['expiring']['4-7'], (string) $m['expiring']['4-7'], 'orange', 'memberships');
            $tile('expiring_8_15', 'Expiring 8–15 days', $m['expiring']['8-15'], (string) $m['expiring']['8-15'], 'amber', 'memberships');
        }
        if ($this->can('payments.view')) {
            $tile('today_collection', 'Today collection', $mo['today'], $short($mo['today']), 'green', 'payments');
            $tile('month_collection', 'This month collection', $mo['month'], $short($mo['month']), 'teal', 'payments');
            $tile('last_month_collection', 'Last month collection', $mo['last_month'], $short($mo['last_month']), 'cyan', 'payments');
            $tile('due_amount', 'Due amount', $mo['due'], $short($mo['due']), 'pink', 'dues');
        }
        if ($this->can('attendance.view') && Entitlements::hasModule('attendance')) {
            $tile('today_checkins', 'Today check-in', $i['attendance']['today'], (string) $i['attendance']['today'], 'fuchsia', 'attendance');
            $tile('inside_now', 'Inside now', $i['attendance']['inside'], (string) $i['attendance']['inside'], 'violet', 'attendance');
        }
        if ($this->can('seats.view')) {
            $tile('seats_occupied', 'Seats occupied', $i['seats']['used'], $i['seats']['used'].' / '.$i['seats']['total'], 'violet', 'seats');
        }
        if ($this->can('expenses.view') && Entitlements::hasModule('expenses')) {
            $tile('month_expenses', 'Expenses this month', $mo['expenses'], $short($mo['expenses']), 'slate', 'expenses');
        }
        if (Entitlements::hasModule('books') && $this->can('circulation.view')) {
            $open = LoanItem::open()->count();
            $tile('books_issued', 'Books issued', $open, (string) $open, 'blue', 'loans');
            $overdue = LoanItem::overdue()->count();
            $tile('overdue_books', 'Overdue books', $overdue, (string) $overdue, 'red', 'loans');
            if ($this->can('fines.view')) {
                $unpaid = (float) Fine::pending()->get()->sum(fn ($f) => (float) $f->outstanding());
                $tile('fines_unpaid', 'Fines unpaid', $unpaid, $short($unpaid), 'amber', 'fines');
            }
        }

        $data = [
            'year' => ['start' => $start, 'label' => AcademicYear::label($start), 'current' => $start === AcademicYear::current()],
            'tiles' => $tiles,
            'setup' => [
                ['step' => 'hall', 'label' => 'Add a hall or floor', 'done' => StudyHall::exists()],
                ['step' => 'seats', 'label' => 'Create seats', 'done' => Seat::exists()],
                ['step' => 'plan', 'label' => 'Create a membership plan', 'done' => MembershipPlan::exists()],
                ['step' => 'student', 'label' => 'Register your first student', 'done' => Student::exists()],
            ],
        ];

        if ($this->can('memberships.view')) {
            $data['expiring'] = StudentMembership::with(['student:id,name,student_code,phone', 'plan:id,name'])->where('status', 'active')
                ->whereBetween('ends_on', [today()->toDateString(), today()->addDays(14)->toDateString()])->orderBy('ends_on')->limit(5)->get()
                ->map(fn ($x) => ['membership_id' => $x->id, 'student' => ['id' => $x->student?->id, 'name' => $x->student?->name, 'code' => $x->student?->student_code],
                    'plan' => $x->plan?->name, 'ends_on' => $this->date($x->ends_on), 'days_left' => (int) today()->diffInDays($x->ends_on, false)])->all();
        }
        if ($this->can('students.view')) {
            $data['new_students'] = Student::whereBetween('registered_on', $range)->latest('id')->limit(5)->get(['id', 'name', 'phone', 'student_code', 'registered_on'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'phone' => $s->phone, 'code' => $s->student_code, 'registered_on' => $this->date($s->registered_on)])->all();
        }
        if ($this->can('payments.view')) {
            $data['recent_payments'] = Payment::with('student:id,name')->whereBetween('paid_on', $range)->latest('id')->limit(5)->get()
                ->map(fn ($p) => ['id' => $p->id, 'receipt_no' => $p->receipt_no, 'student' => $p->student?->name, 'amount' => $this->money($p->amount),
                    'method' => Payment::METHODS[$p->method] ?? $p->method, 'paid_on' => $this->date($p->paid_on), 'reversed' => $p->isReversed()])->all();
            $in = Payment::where('status', Payment::COMPLETED)->whereBetween('paid_on', $range)->selectRaw('method, sum(amount) total')->groupBy('method')->pluck('total', 'method');
            $out = Refund::whereBetween('refunded_on', $range)->selectRaw('method, sum(amount) total')->groupBy('method')->pluck('total', 'method');
            $data['collections_by_method'] = collect($in)->map(fn ($v, $k) => ['method' => $k, 'label' => Payment::METHODS[$k] ?? $k, 'amount' => round(max(0, (float) $v - (float) ($out[$k] ?? 0)), 2)])
                ->sortByDesc('amount')->values()->all();
        }
        if ($this->can('seats.view')) {
            $data['seat_occupancy'] = StudyHall::withCount(['seats', 'seats as occupied' => fn ($q) => $q->where('status', 'occupied')])->orderBy('name')->get()
                ->map(fn ($h) => ['hall_id' => $h->id, 'hall' => $h->name, 'seats' => (int) $h->seats_count, 'occupied' => (int) $h->occupied])->all();
        }
        if (Entitlements::hasModule('books') && $this->can('circulation.view')) {
            $data['overdue_books'] = LoanItem::with(['student:id,name', 'book:id,title'])->overdue()->orderBy('due_on')->limit(5)->get()
                ->map(fn ($l) => ['loan_item_id' => $l->id, 'book' => $l->book?->title, 'student' => $l->student?->name, 'due_on' => $this->date($l->due_on), 'days_overdue' => (int) $l->due_on->diffInDays(today())])->all();
        }
        $data['recent_activity'] = AuditLog::with('actor:id,name')->where('library_id', $this->library()->id)->latest('created_at')->latest('id')->limit(6)->get()
            ->map(fn ($a) => ['action' => $a->action, 'by' => $a->actor?->name, 'at' => $this->time($a->created_at)])->all();

        return $this->ok($data);
    }

    /** Insights screen: member health, collections, attendance week and seats, with the web's plain-language sentences. */
    public function insights(): JsonResponse
    {
        $members = $this->can('students.view') || $this->can('memberships.view');
        $money = $this->can('payments.view');
        $attendance = $this->can('attendance.view') && Entitlements::hasModule('attendance');
        $seats = $this->can('seats.view');
        abort_unless($members || $money || $attendance || $seats, 403);

        $i = LibraryInsights::all();

        return $this->ok(array_filter([
            'window_days' => $i['window'],
            'members' => $members ? $i['members'] + ['sentence' => LibraryInsights::memberSentence($i)] : null,
            'money' => $money ? $i['money'] + ['sentence' => LibraryInsights::moneySentence($i)] : null,
            'attendance' => $attendance ? $i['attendance'] : null,
            'seats' => $seats ? $i['seats'] : null,
        ], fn ($v) => $v !== null));
    }
}
