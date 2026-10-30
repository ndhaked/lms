<?php

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Seat;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Models\StudyHall;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Support\AcademicYear;

new #[Layout('layouts::panel')] class extends Component
{
    protected function can(string $permission): bool
    {
        return auth()->user()?->can($permission) ?? false;
    }

    /** Selected academic year (its start year). Empty = the current year. */
    #[Url(as: 'year')]
    public string $year = '';

    #[Computed]
    public function period(): array
    {
        $start = AcademicYear::resolve($this->year);
        [$from, $to] = AcademicYear::range($start);

        return ['start' => $start, 'from' => $from, 'to' => $to, 'label' => AcademicYear::label($start), 'current' => $start === AcademicYear::current()];
    }

    /** The library-books block: only for libraries with the Books module, and people who may see circulation. */
    #[Computed]
    public function booksInfo(): ?array
    {
        if (! \App\Support\Entitlements::hasModule('books') || ! $this->can('circulation.view')) {
            return null;
        }

        $p = $this->period;
        $range = [$p['from'], $p['to']];
        $cards = [];

        if ($p['current']) {
            $cards[] = ['Books issued', number_format(\App\Models\LoanItem::open()->count()), 'book', 'blue', number_format(\App\Models\LoanItem::whereBetween('issued_at', $range)->whereIn('status', ['issued', 'returned', 'lost'])->count()).' issued in '.$p['label']];
            $cards[] = ['Overdue books', number_format(\App\Models\LoanItem::overdue()->count()), 'alert', 'red', 'Out past their due date'];
        } else {
            $cards[] = ['Books issued', number_format(\App\Models\LoanItem::whereBetween('issued_at', $range)->whereIn('status', ['issued', 'returned', 'lost'])->count()), 'book', 'blue', 'Issued in '.$p['label']];
        }
        $cards[] = ['Books returned', number_format(\App\Models\LoanItem::whereBetween('returned_at', $range)->whereIn('status', ['returned', 'lost'])->count()), 'refresh', 'orange', 'Returned in '.$p['label']];
        if ($this->can('fines.view')) {
            $cards[] = ['Fines collected', '₹'.number_format((float) \App\Models\Fine::where('status', 'paid')->whereBetween('paid_on', [$p['from']->toDateString(), $p['to']->toDateString()])->sum('paid_amount'), 2), 'cash', 'green',
                '₹'.number_format((float) \App\Models\Fine::pending()->get()->sum(fn ($x) => (float) $x->outstanding()), 2).' still unpaid'];
        }
        if (\App\Support\Entitlements::hasModule('ebooks') && $this->can('ebooks.view')) {
            $cards[] = ['E-book reads', number_format(\App\Models\EbookAccessLog::whereBetween('created_at', $range)->count()), 'tablet', 'purple', 'Opened or downloaded in '.$p['label']];
        }

        return [
            'cards' => $cards,
            'overdue' => $p['current'] ? \App\Models\LoanItem::with(['student', 'book'])->overdue()->orderBy('due_on')->limit(5)->get() : collect(),
            'recent' => \App\Models\LoanItem::with(['student', 'book', 'copy'])->whereBetween('issued_at', $range)->latest('issued_at')->latest('id')->limit(5)->get(),
            'trend' => $this->circulationTrend(),
            'byCategory' => $p['current'] ? $this->topCategories(\App\Models\BookCopy::query()->inStock(), 'book_copies', 'book_copies.book_id') : [],
            'ebookUsage' => \App\Support\Entitlements::hasModule('ebooks') && $this->can('ebooks.view')
                ? $this->topCategories(\App\Models\EbookAccessLog::query()->whereBetween('ebook_access_logs.created_at', $range), 'ebook_access_logs', 'ebook_access_logs.book_id') : null,
        ];
    }

    /** Books issued and returned per month across the selected academic year. */
    protected function circulationTrend(): array
    {
        $start = $this->period['from']->copy()->startOfMonth();
        $end = $this->period['to'];
        $issued = \App\Models\LoanItem::whereBetween('issued_at', [$start, $end])->whereIn('status', ['issued', 'returned', 'lost'])->pluck('issued_at')->countBy(fn ($d) => $d->format('Y-m'));
        $returned = \App\Models\LoanItem::whereBetween('returned_at', [$start, $end])->whereIn('status', ['returned', 'lost'])->pluck('returned_at')->countBy(fn ($d) => $d->format('Y-m'));

        return collect(range(0, 11))->map(function ($i) use ($start, $issued, $returned) {
            $m = $start->copy()->addMonths($i);

            return ['label' => $m->format('M'), 'issued' => $issued[$m->format('Y-m')] ?? 0, 'returned' => $returned[$m->format('Y-m')] ?? 0];
        })->all();
    }

    /**
     * Rows (copies or e-book openings) counted by the book's main category: the five biggest, the rest as "Others".
     *
     * @return list<array{label: string, value: int, color: string}>
     */
    protected function topCategories($query, string $table, string $bookColumn): array
    {
        $rows = $query->join('books', 'books.id', '=', $bookColumn)->leftJoin('book_categories as c', 'c.id', '=', 'books.category_id')
            ->selectRaw('coalesce(c.parent_id, c.id) as cat, count(*) as n')->groupBy('cat')->orderByDesc('n')->get();

        $names = \App\Models\BookCategory::whereIn('id', $rows->pluck('cat')->filter())->pluck('name', 'id');
        $colors = ['#2f9e5f', '#3b82f6', '#8b5cf6', '#f59e0b', '#ef4444', '#9ca3af'];
        $out = [];
        foreach ($rows->take(5) as $i => $r) {
            $out[] = ['label' => $r->cat ? ($names[$r->cat] ?? 'Other') : 'Uncategorised', 'value' => (int) $r->n, 'color' => $colors[$i]];
        }
        if ($rest = $rows->slice(5)->sum('n')) {
            $out[] = ['label' => 'Others', 'value' => (int) $rest, 'color' => $colors[5]];
        }

        return $out;
    }

    /** Figures for a finished year: only things that can be answered from dates (no "right now" snapshots). */
    protected function pastStats(array $p): array
    {
        [$f, $t] = [$p['from']->toDateString(), $p['to']->toDateString()];
        $label = $p['label'];
        $cards = [];

        if ($this->can('students.view')) {
            $cards[] = ['New students', number_format(Student::whereBetween('registered_on', [$f, $t])->count()), 'users', 'green', "Registered in {$label}"];
        }
        if ($this->can('memberships.view')) {
            $started = StudentMembership::whereBetween('starts_on', [$f, $t]);
            $cards[] = ['Memberships started', number_format((clone $started)->count()), 'badge', 'blue', (clone $started)->where('status', 'cancelled')->count().' cancelled'];
        }
        if ($this->can('attendance.view')) {
            $visits = Attendance::whereBetween('checked_in_at', [$p['from'], $p['to']]);
            $cards[] = ['Check-ins', number_format((clone $visits)->count()), 'clock', 'orange', (clone $visits)->distinct()->count('student_id').' different students'];
        }
        if ($this->can('payments.view')) {
            $refunds = (float) Refund::whereBetween('refunded_on', [$f, $t])->sum('amount');
            $cards[] = ["Collected in {$label}", '₹'.number_format($this->collected($p['from'], $p['to']), 2), 'cash', 'green', 'After ₹'.number_format($refunds, 2).' refunds'];
            $cards[] = ['Dues still open', '₹'.number_format((float) StudentMembership::owing()->withBalance()->whereBetween('starts_on', [$f, $t])->get()->sum('balance'), 2), 'alert', 'red', "From memberships started in {$label}"];
        }

        return $cards;
    }
    public function mount()
    {
        // A student login has nothing to manage here: send it to its own page.
        $staffView = collect(['students.view', 'seats.view', 'memberships.view', 'attendance.view', 'payments.view', 'expenses.view', 'reports.operational', 'reports.financial', 'staff.manage', 'settings.manage', 'billing.view'])
            ->contains(fn ($p) => $this->can($p));

        if (! $staffView && $this->can('self.view')) {
            return $this->redirect(route('app.my.index', ['library' => \App\Support\Tenancy::slug()]), navigate: true);
        }
    }

    /** True until the library has at least a hall, seats and a plan: shows the setup checklist. */
    #[Computed]
    public function setup(): array
    {
        return [
            ['Add a hall or floor', 'app.halls.index', StudyHall::exists()],
            ['Create seats', 'app.seats.index', Seat::exists()],
            ['Create a membership plan', 'app.plans.index', MembershipPlan::exists()],
            ['Register your first student', 'app.students.create', Student::exists()],
        ];
    }

    #[Computed]
    public function stats(): array
    {
        if (! $this->period['current']) {
            return $this->pastStats($this->period);
        }

        $cards = [];

        if ($this->can('students.view')) {
            $cards[] = ['Total students', number_format(Student::count()), 'users', 'green', Student::where('status', 'active')->count().' active'];
        }
        if ($this->can('memberships.view')) {
            $cards[] = ['Active memberships', number_format(StudentMembership::current()->count()), 'badge', 'blue', StudentMembership::expiringWithin((int) \App\Support\LibrarySettings::get("renewal_window_days"))->count().' expiring in '.(int) \App\Support\LibrarySettings::get('renewal_window_days').' days'];
        }
        if ($this->can('seats.view')) {
            $total = Seat::count();
            $occupied = Seat::where('status', 'occupied')->count();
            $cards[] = ['Seats occupied', "{$occupied} / {$total}", 'seat', 'purple', Seat::where('status', 'available')->count().' available'];
        }
        if ($this->can('attendance.view')) {
            $cards[] = ['Checked in today', number_format(Attendance::whereDate('checked_in_at', today())->count()), 'clock', 'orange', Attendance::whereNull('checked_out_at')->count().' inside now'];
        }
        if ($this->can('memberships.view')) {
            $cards[] = ['Expired memberships', number_format(StudentMembership::where('status', 'active')->whereDate('ends_on', '<', today())->count()), 'alert', 'red', 'Need renewal'];
        }
        if ($this->can('payments.view')) {
            $cards[] = ["Today's collection", '₹'.number_format($this->collected(today(), today()), 2), 'cash', 'green', '₹'.number_format($this->collected(now()->startOfMonth(), now()->endOfMonth()), 2).' this month'];
            $cards[] = ['Outstanding dues', '₹'.number_format((float) StudentMembership::owing()->withBalance()->get()->sum('balance'), 2), 'alert', 'red', 'Across all memberships'];
        }

        return $cards;
    }

    protected function collected($from, $to): float
    {
        $in = Payment::where('status', Payment::COMPLETED)->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->sum('amount');
        $out = Refund::whereBetween('refunded_on', [$from->toDateString(), $to->toDateString()])->sum('amount');

        return (float) $in - (float) $out;
    }

    /** Check-ins and new registrations per month across the selected academic year. */
    #[Computed]
    public function trend(): array
    {
        $start = $this->period['from']->copy()->startOfMonth();
        $end = $this->period['to'];
        $visits = Attendance::whereBetween('checked_in_at', [$start, $end])->pluck('checked_in_at')->countBy(fn ($d) => $d->format('Y-m'));
        $joined = Student::whereBetween('registered_on', [$start->toDateString(), $end->toDateString()])->pluck('registered_on')->countBy(fn ($d) => $d->format('Y-m'));

        return collect(range(0, 11))->map(function ($i) use ($start, $visits, $joined) {
            $m = $start->copy()->addMonths($i);

            return ['label' => $m->format('M'), 'visits' => $visits[$m->format('Y-m')] ?? 0, 'joined' => $joined[$m->format('Y-m')] ?? 0];
        })->all();
    }

    /** Occupied seats per hall plus everything still free, for the donut. */
    #[Computed]
    public function occupancy(): array
    {
        $colors = ['#2f9e5f', '#3b82f6', '#8b5cf6', '#f59e0b', '#ef4444', '#14b8a6'];
        $rows = [];
        foreach (StudyHall::withCount(['seats as occupied' => fn ($q) => $q->where('status', 'occupied')])->orderBy('name')->get() as $i => $hall) {
            $rows[] = ['label' => $hall->name, 'value' => (int) $hall->occupied, 'color' => $colors[$i % count($colors)]];
        }
        $free = Seat::where('status', '!=', 'occupied')->count();
        $rows[] = ['label' => 'Not occupied', 'value' => $free, 'color' => '#d1d5db'];

        return array_values(array_filter($rows, fn ($r) => $r['value'] > 0));
    }

    #[Computed]
    public function activity()
    {
        return AuditLog::with('actor')->where('library_id', \App\Support\Tenancy::currentId())->whereBetween('created_at', [$this->period['from'], $this->period['to']])->latest('created_at')->latest('id')->limit(6)->get();
    }

    #[Computed]
    public function expiring()
    {
        if (! $this->period['current']) {   // a finished year: the memberships that started in it
            return StudentMembership::with(['student', 'plan'])->whereBetween('starts_on', [$this->period['from']->toDateString(), $this->period['to']->toDateString()])->latest('starts_on')->limit(5)->get();
        }

        return StudentMembership::with(['student', 'plan'])->where('status', 'active')
            ->whereBetween('ends_on', [today()->toDateString(), today()->addDays(14)->toDateString()])->orderBy('ends_on')->limit(5)->get();
    }

    #[Computed]
    public function newStudents()
    {
        return Student::whereBetween('registered_on', [$this->period['from']->toDateString(), $this->period['to']->toDateString()])->latest('id')->limit(5)->get();
    }

    #[Computed]
    public function methods(): array
    {
        $range = [$this->period['from']->toDateString(), $this->period['to']->toDateString()];
        $in = Payment::where('status', Payment::COMPLETED)->whereBetween('paid_on', $range)
            ->selectRaw('method, sum(amount) total')->groupBy('method')->pluck('total', 'method');
        $out = Refund::whereBetween('refunded_on', $range)
            ->selectRaw('method, sum(amount) total')->groupBy('method')->pluck('total', 'method');

        return collect($in)->map(fn ($v, $k) => ['label' => Payment::METHODS[$k] ?? $k, 'value' => max(0, (float) $v - (float) ($out[$k] ?? 0))])
            ->sortByDesc('value')->values()->all();
    }

    #[Computed]
    public function recentPayments()
    {
        return Payment::with('student')->whereBetween('paid_on', [$this->period['from']->toDateString(), $this->period['to']->toDateString()])->latest('id')->limit(5)->get();
    }
}
?>

<div class="space-y-5">
    @php
        $tones = ['green' => 'bg-brand-100 text-brand-600', 'blue' => 'bg-blue-100 text-blue-600', 'purple' => 'bg-violet-100 text-violet-600', 'orange' => 'bg-orange-100 text-orange-500', 'red' => 'bg-red-100 text-red-500'];
        $card = 'rounded-xl border border-gray-200 bg-white';
        $th = 'pb-2 text-left text-[11px] font-medium text-gray-500';
        $slug = \App\Support\Tenancy::slug();
        $setup = $this->setup;
        $setupDone = collect($setup)->every(fn ($s) => $s[2]);
        $can = fn ($p) => auth()->user()->can($p);

        // line chart geometry (check-ins + new students per month)
        $trend = $this->trend;
        $peak = max(1, max(array_merge(array_column($trend, 'visits'), array_column($trend, 'joined'))));
        $step = max(1, (int) ceil($peak / 4 / 5) * 5);   // tidy gridline step
        $max = $step * 4;
        [$w, $h, $pl, $pr, $pt, $pb] = [520, 200, 38, 10, 10, 26];
        $x = fn ($i) => $pl + $i * (($w - $pl - $pr) / 11);
        $y = fn ($v) => $pt + ($h - $pt - $pb) * (1 - $v / $max);
        $path = fn ($key) => collect($trend)->map(fn ($t, $i) => ($i ? 'L' : 'M').round($x($i), 1).' '.round($y($t[$key]), 1))->join(' ');
        $area = fn ($key) => $path($key).' L'.round($x(11), 1).' '.($h - $pb).' L'.round($x(0), 1).' '.($h - $pb).' Z';

        // donut
        $occ = $this->occupancy;
        $occTotal = array_sum(array_column($occ, 'value'));
        $occupiedTotal = collect($occ)->where('label', '!=', 'Not occupied')->sum('value');
        $r = 62; $circ = 2 * M_PI * $r; $offset = 0;

        $methods = $this->methods;
        $methodMax = max(1, collect($methods)->max('value') ?? 1);
        $methodTotal = collect($methods)->sum('value');
        $nice = fn ($action) => \Illuminate\Support\Str::of($action)->replace(['.', '_'], ' ')->headline();
    @endphp

    <x-slot:header><x-ui.page-title title="Dashboard" subtitle="Overview of library operations and insights" /></x-slot:header>

    @php $heroLib = \App\Support\Tenancy::library(); @endphp
    <section class="flex flex-wrap items-start justify-between gap-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <div>
            <p class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-brand-700">✦ Dashboard</p>
            <h2 class="mt-3 text-2xl font-extrabold text-gray-900 sm:text-3xl">Welcome to {{ $heroLib?->name }}@if ($heroLib?->code) <span class="text-gray-400">({{ $heroLib->code }})</span>@endif</h2>
            <p class="mt-1 text-sm text-gray-500">Live operations, revenue signals, member movement and expiring plans in one place.</p>
        </div>
        @if ($can('students.view') || $can('memberships.view') || $can('payments.view') || $can('attendance.view'))
            <a href="{{ route('app.insights', ['library' => $slug]) }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-bold text-white shadow hover:bg-brand-800">✦ View Insights</a>
        @endif
    </section>

    @if (! $setupDone)
        <section class="{{ $card }} border-brand-200 bg-brand-50/50 p-5">
            <h3 class="text-sm font-semibold text-gray-900">Set up your library</h3>
            <p class="mb-3 mt-0.5 text-xs text-gray-500">A few steps and you are ready to take students.</p>
            <ol class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($setup as $i => [$label, $route, $done])
                    <li>
                        <a href="{{ route($route, ['library' => $slug]) }}" wire:navigate class="flex items-center gap-3 rounded-lg border bg-white px-3 py-2.5 text-sm {{ $done ? 'border-brand-200 text-gray-500' : 'border-gray-200 text-gray-800 hover:border-brand-300' }}">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $done ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-500' }}">{!! $done ? '✓' : $i + 1 !!}</span>
                            <span class="{{ $done ? 'line-through' : '' }}">{{ $label }}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @include('partials.dashboard-tools')

    {{-- insights, tiles (current year) or the year's own cards (a finished year) --}}
    @if ($this->period['current'])
        @php
            $ins = \App\Support\LibraryInsights::all();
            $im = $ins['members']; $imo = $ins['money']; $iat = $ins['attendance']; $ise = $ins['seats'];
            $short = fn ($v) => \App\Support\LibraryInsights::short($v);
            $canMembers = $can('students.view') || $can('memberships.view');
            $canMoney = $can('payments.view');
            $canAttend = $can('attendance.view');
            $route = fn ($name) => route($name, ['library' => $slug]);
            $tiles = [];
            if ($can('students.view')) {
                $tiles[] = ['Live members', $im['live'], 'users', 'from-indigo-600 to-violet-500', $route('app.students.index')];
                $tiles[] = ['Total members', $im['total'], 'user-group', 'from-sky-600 to-blue-500', $route('app.students.index')];
            }
            if ($can('memberships.view')) {
                $tiles[] = ['Expired members', $im['expired'], 'alert', 'from-red-600 to-rose-500', $route('app.memberships.index')];
                $tiles[] = ['Expiring 1–3 days', $im['expiring']['1-3'], 'clock', 'from-rose-500 to-red-400', $route('app.memberships.index')];
                $tiles[] = ['Expiring 4–7 days', $im['expiring']['4-7'], 'clock', 'from-orange-500 to-red-400', $route('app.memberships.index')];
                $tiles[] = ['Expiring 8–15 days', $im['expiring']['8-15'], 'clock', 'from-amber-500 to-orange-400', $route('app.memberships.index')];
            }
            if ($canMoney) {
                $tiles[] = ['Today collection', $short($imo['today']), 'cash', 'from-emerald-600 to-teal-500', $route('app.payments.index')];
                $tiles[] = ['This month collection', $short($imo['month']), 'cash', 'from-teal-600 to-emerald-500', $route('app.payments.index')];
                $tiles[] = ['Last month collection', $short($imo['last_month']), 'cash', 'from-cyan-600 to-teal-500', $route('app.payments.index')];
                $tiles[] = ['Due amount', $short($imo['due']), 'alert', 'from-pink-600 to-fuchsia-500', $route('app.dues.index')];
            }
            if ($canAttend) {
                $tiles[] = ['Today check-in', $iat['today'], 'clock', 'from-fuchsia-600 to-pink-500', $route('app.attendance.index')];
            }
            if ($can('seats.view')) {
                $tiles[] = ['Seats occupied', $ise['used'].' / '.$ise['total'], 'seat', 'from-violet-600 to-purple-500', $route('app.seats.index')];
            }
            if ($can('expenses.view')) {
                $tiles[] = ['Expenses this month', $short($imo['expenses']), 'receipt', 'from-slate-600 to-gray-500', $route('app.expenses.index')];
            }
            $pressure = [];
            if ($can('memberships.view')) { $pressure[] = ['Expired', $im['expired_pct'], '#ef4444']; $pressure[] = ['Expiring', $im['live'] > 0 ? (int) round($im['expiring']['window'] / $im['live'] * 100) : 0, '#f59e0b']; }
            if ($canMoney) { $pressure[] = ['Due', $imo['due_risk'], '#f97316']; }
            if ($canAttend) { $pressure[] = ['Not in today', max(0, 100 - $iat['pct']), '#8b5cf6']; }
            if ($can('seats.view')) { $pressure[] = ['Seats used', $ise['pct'], '#3b82f6']; }
        @endphp

        @if ($canMembers || $canMoney || $canAttend)
            <section class="rounded-2xl border border-gray-200 bg-gray-50/60 p-4 sm:p-5">
                <div class="mb-4 flex flex-wrap items-end justify-between gap-2"><div><h3 class="text-lg font-extrabold text-gray-900">Dashboard insights</h3><p class="text-xs text-gray-500">Worked out from your library's current numbers</p></div>
                    <a href="{{ route('app.insights', ['library' => $slug]) }}" wire:navigate class="text-sm font-bold text-brand-700 hover:text-brand-800">See all insights →</a></div>
                <div class="grid gap-4 lg:grid-cols-[1fr_1fr_1fr_0.9fr]">
                    @if ($canMembers)
                        <div class="rounded-2xl border border-gray-200 border-t-4 border-t-emerald-500 bg-white p-4 shadow-sm">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Member insight</p>
                            <p class="text-xl font-extrabold text-gray-900">{{ $im['live_pct'] }}% Live</p><p class="mb-3 text-xs text-gray-500">{{ $im['live'] }} live / {{ $im['expired'] }} expired / {{ $im['expiring']['window'] }} expiring</p>
                            <x-lm.donut :segments="[['label' => 'Live', 'value' => max(0, $im['live'] - $im['expiring']['window']), 'color' => '#10b981'], ['label' => 'Expired', 'value' => $im['expired'], 'color' => '#ef4444'], ['label' => 'Expiring', 'value' => $im['expiring']['window'], 'color' => '#f59e0b']]" :center="$im['live']" sub="live" :size="130" />
                        </div>
                    @endif
                    @if ($canMoney)
                        @php $cb = [['Collected', $imo['month'], '#10b981'], ['Due', $imo['due'], '#f59e0b'], ['Expense', $imo['expenses'], '#ef4444']]; $cbMax = max(1, max(array_column($cb, 1))); @endphp
                        <div class="rounded-2xl border border-gray-200 border-t-4 border-t-amber-500 bg-white p-4 shadow-sm">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Collection insight</p>
                            <p class="text-xl font-extrabold text-gray-900">{{ $imo['due_risk'] }}% Due risk</p><p class="mb-3 text-xs text-gray-500">{{ $short($imo['month']) }} collected / {{ $short($imo['due']) }} due this month</p>
                            <div class="flex h-[170px] items-end justify-around gap-3">
                                @foreach ($cb as [$l, $v, $c])
                                    <div class="flex h-full flex-1 flex-col items-center justify-end gap-1"><span class="text-[10px] font-semibold text-gray-600">{{ $short($v) }}</span><div class="w-full max-w-[44px] rounded-t-md" style="height: {{ max(3, $v / $cbMax * 100) }}%; background: {{ $c }}"></div><span class="text-[10px] text-gray-500">{{ $l }}</span></div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @if ($canAttend)
                        <div class="rounded-2xl border border-gray-200 border-t-4 border-t-violet-500 bg-white p-4 shadow-sm">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Attendance insight</p>
                            <p class="text-xl font-extrabold text-gray-900">{{ $iat['pct'] }}% Checked in</p><p class="mb-3 text-xs text-gray-500">{{ $iat['today'] }} checked in from {{ $im['live'] }} live members</p>
                            <x-lm.donut :segments="[['label' => 'Checked in', 'value' => min($iat['today'], max($im['live'], $iat['today'])), 'color' => '#8b5cf6'], ['label' => 'Not checked in', 'value' => max(0, $im['live'] - $iat['today']), 'color' => '#d1d5db']]" :center="$iat['today']" sub="today" :size="130" />
                        </div>
                    @endif
                    @if ($pressure)
                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                            <p class="text-base font-extrabold text-gray-900">Operational pressure</p><p class="mb-4 text-xs text-gray-500">Expired, due, expiring, attendance and seats</p>
                            <div class="space-y-3.5">
                                @foreach ($pressure as [$l, $v, $c])
                                    <div><div class="mb-1 flex justify-between text-xs"><span class="font-semibold text-gray-700">{{ $l }}</span><span class="font-bold text-gray-900">{{ $v }}%</span></div><div class="h-3 rounded-full bg-gray-100"><div class="h-3 rounded-full" style="width: {{ max(2, min(100, $v)) }}%; background: {{ $c }}"></div></div></div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </section>
        @endif

        @if ($tiles)
            <section class="space-y-3">
                <p class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-brand-700">● All report</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($tiles as [$label, $value, $icon, $grad, $href])
                        <a href="{{ $href }}" wire:navigate wire:key="tile-{{ $loop->index }}" class="group relative flex items-center gap-4 overflow-hidden rounded-2xl bg-gradient-to-br {{ $grad }} p-4 text-white shadow-md transition hover:-translate-y-0.5 hover:shadow-lg">
                            <span class="pointer-events-none absolute -bottom-8 -right-6 h-24 w-24 rounded-full bg-white/15"></span>
                            <span class="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/25"><x-lm.icon :name="$icon" class="h-6 w-6" /></span>
                            <span class="relative min-w-0"><span class="block text-xs font-semibold opacity-90">{{ $label }}</span><span class="block truncate text-2xl font-extrabold">{{ $value }}</span></span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @elseif ($this->stats)
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
            @foreach ($this->stats as [$label, $value, $icon, $tone, $hint])
                <x-ui.stat :label="$label" :value="$value" :icon="$icon" :tone="$tone" :hint="$hint" wire:key="stat-{{ $loop->index }}" />
            @endforeach
        </div>
    @endif

    {{-- library books --}}
    @if ($this->booksInfo)
        @php
            $bk = $this->booksInfo;
            $cur = $this->period['current'];

            // circulation line chart (issued vs returned per month)
            $ct = $bk['trend'];
            $cPeak = max(1, max(array_merge(array_column($ct, 'issued'), array_column($ct, 'returned'))));
            $cStep = max(1, (int) ceil($cPeak / 4));
            $cMax = $cStep * 4;
            [$cw, $ch, $cpl, $cpr, $cpt, $cpb] = [520, 200, 38, 10, 10, 26];
            $cx = fn ($i) => $cpl + $i * (($cw - $cpl - $cpr) / 11);
            $cy = fn ($v) => $cpt + ($ch - $cpt - $cpb) * (1 - $v / $cMax);
            $cPath = fn ($key) => collect($ct)->map(fn ($t, $i) => ($i ? 'L' : 'M').round($cx($i), 1).' '.round($cy($t[$key]), 1))->join(' ');
            $cArea = fn ($key) => $cPath($key).' L'.round($cx(11), 1).' '.($ch - $cpb).' L'.round($cx(0), 1).' '.($ch - $cpb).' Z';

            $catRows = $bk['byCategory'];
            $catTotal = array_sum(array_column($catRows, 'value'));
            $dr = 62; $dCirc = 2 * M_PI * $dr; $dOff = 0;

            $eb = $bk['ebookUsage'];
            $ebMax = max(1, collect($eb ?? [])->max('value') ?? 1);
            $ebTotal = collect($eb ?? [])->sum('value');
        @endphp

        @php $bkGrad = ['blue' => 'from-sky-600 to-blue-500', 'red' => 'from-red-600 to-rose-500', 'orange' => 'from-orange-500 to-amber-400', 'green' => 'from-emerald-600 to-teal-500', 'purple' => 'from-violet-600 to-purple-500']; @endphp
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($bk['cards'] as [$label, $value, $icon, $tone, $hint])
                <div wire:key="bk-{{ $loop->index }}" class="relative flex items-center gap-4 overflow-hidden rounded-2xl bg-gradient-to-br {{ $bkGrad[$tone] ?? 'from-slate-600 to-gray-500' }} p-4 text-white shadow-md">
                    <span class="pointer-events-none absolute -bottom-8 -right-6 h-24 w-24 rounded-full bg-white/15"></span>
                    <span class="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/25"><x-lm.icon :name="$icon" class="h-6 w-6" /></span>
                    <span class="relative min-w-0"><span class="block text-xs font-semibold opacity-90">{{ $label }}</span><span class="block truncate text-2xl font-extrabold">{{ $value }}</span>@if ($hint)<span class="block truncate text-[11px] opacity-80">{{ $hint }}</span>@endif</span>
                </div>
            @endforeach
        </div>

        <div class="grid gap-4 xl:grid-cols-12">
            {{-- circulation overview --}}
            <div class="rounded-2xl border border-gray-200 border-t-4 border-t-sky-500 bg-white p-5 shadow-sm xl:col-span-5">
                <div class="mb-1 flex items-center justify-between">
                    <h3 class="text-base font-extrabold text-gray-900">Circulation Overview</h3>
                    <span class="shrink-0 whitespace-nowrap rounded-lg border border-gray-200 px-2.5 py-1 text-[11px] text-gray-600">{{ $this->period['label'] }}</span>
                </div>
                <div class="mb-1 flex items-center gap-4 text-[11px] text-gray-600">
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-brand-600"></span>Issued</span>
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-blue-500"></span>Returned</span>
                </div>
                <svg viewBox="0 0 {{ $cw }} {{ $ch }}" class="w-full" role="img" aria-label="Books issued and returned per month">
                    <defs>
                        <linearGradient id="cgA" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2f9e5f" stop-opacity=".22"/><stop offset="1" stop-color="#2f9e5f" stop-opacity="0"/></linearGradient>
                        <linearGradient id="cgB" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3b82f6" stop-opacity=".18"/><stop offset="1" stop-color="#3b82f6" stop-opacity="0"/></linearGradient>
                    </defs>
                    @foreach (range(0, 4) as $g)
                        @php $tv = $g * $cStep; @endphp
                        <line x1="{{ $cpl }}" x2="{{ $cw - $cpr }}" y1="{{ $cy($tv) }}" y2="{{ $cy($tv) }}" stroke="#eef0f3" />
                        <text x="{{ $cpl - 8 }}" y="{{ $cy($tv) + 3 }}" text-anchor="end" font-size="9" fill="#6b7280">{{ $tv }}</text>
                    @endforeach
                    @foreach ($ct as $i => $t)
                        <text x="{{ $cx($i) }}" y="{{ $ch - 8 }}" text-anchor="middle" font-size="9" fill="#6b7280">{{ $t['label'] }}</text>
                    @endforeach
                    <path d="{{ $cArea('issued') }}" fill="url(#cgA)" /><path d="{{ $cArea('returned') }}" fill="url(#cgB)" />
                    <path d="{{ $cPath('issued') }}" fill="none" stroke="#2f9e5f" stroke-width="1.8" stroke-linejoin="round" />
                    <path d="{{ $cPath('returned') }}" fill="none" stroke="#3b82f6" stroke-width="1.8" stroke-linejoin="round" />
                    @foreach ($ct as $i => $t)
                        <circle cx="{{ $cx($i) }}" cy="{{ $cy($t['issued']) }}" r="2.6" fill="#fff" stroke="#2f9e5f" stroke-width="1.5"><title>{{ $t['label'] }}: {{ $t['issued'] }} issued</title></circle>
                        <circle cx="{{ $cx($i) }}" cy="{{ $cy($t['returned']) }}" r="2.6" fill="#fff" stroke="#3b82f6" stroke-width="1.5"><title>{{ $t['label'] }}: {{ $t['returned'] }} returned</title></circle>
                    @endforeach
                </svg>
            </div>

            {{-- books by category --}}
            <div class="rounded-2xl border border-gray-200 border-t-4 border-t-violet-500 bg-white p-5 shadow-sm xl:col-span-4">
                <h3 class="mb-3 text-base font-extrabold text-gray-900">Books by Category</h3>
                @if (! $cur)
                    <p class="py-10 text-center text-sm text-gray-400">Stock is live data, so it is shown for the current academic year only.</p>
                @elseif ($catTotal === 0)
                    <p class="py-10 text-center text-sm text-gray-400">No books yet. Add books to see them by category.</p>
                @else
                    <div class="flex items-center gap-3">
                        <svg viewBox="0 0 160 160" class="h-32 w-32 shrink-0 -rotate-90">
                            @foreach ($catRows as $seg)
                                @php $len = $seg['value'] / $catTotal * $dCirc; @endphp
                                <circle cx="80" cy="80" r="{{ $dr }}" fill="none" stroke="{{ $seg['color'] }}" stroke-width="22" stroke-dasharray="{{ round(max($len - 1.5, 0.5), 2) }} {{ round($dCirc - $len + 1.5, 2) }}" stroke-dashoffset="{{ -round($dOff, 2) }}"><title>{{ $seg['label'] }}: {{ $seg['value'] }}</title></circle>
                                @php $dOff += $len; @endphp
                            @endforeach
                            <g class="rotate-90" style="transform-origin: 80px 80px">
                                <text x="80" y="80" text-anchor="middle" font-size="22" font-weight="700" fill="#111827">{{ number_format($catTotal) }}</text>
                                <text x="80" y="97" text-anchor="middle" font-size="9" fill="#6b7280">Total Books</text>
                            </g>
                        </svg>
                        <ul class="flex-1 space-y-2.5 text-[11px] text-gray-600">
                            @foreach ($catRows as $seg)
                                <li class="flex items-center gap-2" wire:key="cat-{{ $loop->index }}"><span class="h-2 w-2 shrink-0 rounded-full" style="background: {{ $seg['color'] }}"></span><span class="flex-1 truncate">{{ $seg['label'] }}</span>
                                    <span class="font-medium text-gray-700">{{ number_format($seg['value']) }} <span class="text-gray-400">({{ round($seg['value'] / $catTotal * 100, 1) }}%)</span></span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            {{-- e-book usage --}}
            @if ($eb !== null)
                <div class="rounded-2xl border border-gray-200 border-t-4 border-t-purple-500 bg-white p-5 shadow-sm xl:col-span-3">
                    <div class="mb-3 flex items-center justify-between gap-2"><h3 class="text-base font-extrabold text-gray-900">E-Book Usage</h3><span class="shrink-0 whitespace-nowrap rounded-lg border border-gray-200 px-2.5 py-1 text-[11px] text-gray-600">{{ $this->period['label'] }}</span></div>
                    <ul class="space-y-3">
                        @forelse ($eb as $row)
                            <li wire:key="ebu-{{ $loop->index }}"><div class="mb-1 flex items-center justify-between text-[11px]"><span class="truncate text-gray-700">{{ $row['label'] }}</span><span class="font-medium text-gray-900">{{ number_format($row['value']) }}</span></div>
                                <div class="h-2 rounded-full bg-gray-100"><div class="h-2 rounded-full bg-gradient-to-r from-emerald-500 to-teal-500" style="width: {{ round($row['value'] / $ebMax * 100) }}%"></div></div></li>
                        @empty
                            <li class="py-6 text-center text-xs text-gray-400">No e-books opened in {{ $this->period['label'] }}.</li>
                        @endforelse
                    </ul>
                    <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-3 text-[11px]"><span class="font-medium text-gray-700">Total e-book accesses</span><span class="text-sm font-bold text-brand-600">{{ number_format($ebTotal) }}</span></div>
                </div>
            @else
                <div class="rounded-2xl border border-gray-200 border-t-4 border-t-amber-500 bg-white p-5 shadow-sm xl:col-span-3">
                    <h3 class="mb-3 text-base font-extrabold text-gray-900">Quick Look</h3>
                    <p class="py-6 text-center text-xs text-gray-400">E-books are not enabled for this library.</p>
                </div>
            @endif
        </div>

        <div class="grid gap-4 xl:grid-cols-12">
            @if ($cur)
                <div class="rounded-2xl border border-gray-200 border-t-4 border-t-red-500 bg-white p-5 shadow-sm xl:col-span-5">
                    <div class="mb-3 flex items-center justify-between"><h3 class="text-base font-extrabold text-gray-900">Overdue Books</h3><a href="{{ route('app.circulation.loans', ['library' => $slug, 'status' => 'overdue']) }}" wire:navigate class="text-xs font-bold text-brand-700 hover:text-brand-800">View all</a></div>
                    <table class="w-full text-[11px]">
                        <thead><tr><th class="{{ $th }}">Title</th><th class="{{ $th }}">Member</th><th class="{{ $th }}">Due Date</th><th class="{{ $th }} text-right">Days Overdue</th></tr></thead>
                        <tbody class="text-gray-700">
                            @forelse ($bk['overdue'] as $i)
                                <tr class="border-t border-gray-100" wire:key="od-{{ $i->id }}"><td class="max-w-40 truncate py-2 font-medium">{{ $i->book->title }}</td><td class="py-2 text-gray-500">{{ $i->student->name }}</td><td class="py-2">{{ $i->due_on->format('d M Y') }}</td><td class="py-2 text-right font-semibold text-red-500">{{ $i->lateDays() }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="py-6 text-center text-gray-400">No overdue books.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
            <div class="rounded-2xl border border-gray-200 border-t-4 border-t-blue-500 bg-white p-5 shadow-sm {{ $cur ? 'xl:col-span-7' : 'xl:col-span-12' }}">
                <div class="mb-3 flex items-center justify-between"><h3 class="text-base font-extrabold text-gray-900">Recently Issued Books</h3><a href="{{ route('app.circulation.loans', ['library' => $slug, 'status' => 'all']) }}" wire:navigate class="text-xs font-bold text-brand-700 hover:text-brand-800">View all</a></div>
                <table class="w-full text-[11px]">
                    <thead><tr><th class="{{ $th }}">Book Title</th><th class="{{ $th }}">Author</th><th class="{{ $th }}">Member</th><th class="{{ $th }}">Issued On</th><th class="{{ $th }}">Due On</th><th class="{{ $th }}">Barcode</th></tr></thead>
                    <tbody class="text-gray-700">
                        @forelse ($bk['recent'] as $i)
                            <tr class="border-t border-gray-100" wire:key="ri-{{ $i->id }}"><td class="max-w-36 truncate py-2 font-semibold text-gray-900">{{ $i->book->title }}</td><td class="max-w-28 truncate py-2 text-gray-500">{{ $i->book->author ?? '—' }}</td><td class="py-2">{{ $i->student->name }}</td><td class="py-2">{{ $i->issued_at->format('d M Y') }}</td><td class="py-2">{{ $i->due_on->format('d M Y') }}</td><td class="py-2 text-gray-500">{{ $i->copy->barcode }}</td></tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">No books issued yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
    {{-- chart / donut / activities --}}
    <div class="grid gap-4 xl:grid-cols-12">
        @if ($can('attendance.view') || $can('students.view'))
            <div class="rounded-2xl border border-gray-200 border-t-4 border-t-orange-500 bg-white p-5 shadow-sm xl:col-span-5">
                <div class="mb-1 flex items-center justify-between">
                    <h3 class="text-base font-extrabold text-gray-900">Attendance &amp; Enrolment Overview</h3>
                    <span class="shrink-0 whitespace-nowrap rounded-lg border border-gray-200 px-2.5 py-1 text-[11px] text-gray-600">{{ $this->period['label'] }}</span>
                </div>
                <div class="mb-1 flex items-center gap-4 text-[11px] text-gray-600">
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-brand-600"></span>Check-ins</span>
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-blue-500"></span>New students</span>
                </div>
                <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full" role="img" aria-label="Check-ins and new students per month">
                    <defs>
                        <linearGradient id="gA" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2f9e5f" stop-opacity=".22"/><stop offset="1" stop-color="#2f9e5f" stop-opacity="0"/></linearGradient>
                        <linearGradient id="gB" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3b82f6" stop-opacity=".18"/><stop offset="1" stop-color="#3b82f6" stop-opacity="0"/></linearGradient>
                    </defs>
                    @foreach (range(0, 4) as $g)
                        @php $t = $g * $step; @endphp
                        <line x1="{{ $pl }}" x2="{{ $w - $pr }}" y1="{{ $y($t) }}" y2="{{ $y($t) }}" stroke="#eef0f3" />
                        <text x="{{ $pl - 8 }}" y="{{ $y($t) + 3 }}" text-anchor="end" font-size="9" fill="#6b7280">{{ $t >= 1000 ? round($t / 1000, 1).'K' : $t }}</text>
                    @endforeach
                    @foreach ($trend as $i => $t)
                        <text x="{{ $x($i) }}" y="{{ $h - 8 }}" text-anchor="middle" font-size="9" fill="#6b7280">{{ $t['label'] }}</text>
                    @endforeach
                    <path d="{{ $area('visits') }}" fill="url(#gA)" /><path d="{{ $area('joined') }}" fill="url(#gB)" />
                    <path d="{{ $path('visits') }}" fill="none" stroke="#2f9e5f" stroke-width="1.8" stroke-linejoin="round" />
                    <path d="{{ $path('joined') }}" fill="none" stroke="#3b82f6" stroke-width="1.8" stroke-linejoin="round" />
                    @foreach ($trend as $i => $t)
                        <circle cx="{{ $x($i) }}" cy="{{ $y($t['visits']) }}" r="2.6" fill="#fff" stroke="#2f9e5f" stroke-width="1.5"><title>{{ $t['label'] }}: {{ $t['visits'] }} check-ins</title></circle>
                        <circle cx="{{ $x($i) }}" cy="{{ $y($t['joined']) }}" r="2.6" fill="#fff" stroke="#3b82f6" stroke-width="1.5"><title>{{ $t['label'] }}: {{ $t['joined'] }} new students</title></circle>
                    @endforeach
                </svg>
            </div>
        @endif

        @if ($can('seats.view'))
            <div class="rounded-2xl border border-gray-200 border-t-4 border-t-indigo-500 bg-white p-5 shadow-sm xl:col-span-4">
                <h3 class="mb-3 text-base font-extrabold text-gray-900">Seat Occupancy by Hall</h3>
                @if (! $this->period['current'])
                    <p class="py-10 text-center text-sm text-gray-400">Seat occupancy is live data, so it is shown for the current academic year only.</p>
                @elseif ($occTotal === 0)
                    <p class="py-10 text-center text-sm text-gray-400">No seats yet. Add a hall and generate seats to see occupancy here.</p>
                @else
                    <div class="flex items-center gap-5">
                        <svg viewBox="0 0 160 160" class="h-40 w-40 shrink-0 -rotate-90">
                            @foreach ($occ as $seg)
                                @php $len = $seg['value'] / $occTotal * $circ; @endphp
                                <circle cx="80" cy="80" r="{{ $r }}" fill="none" stroke="{{ $seg['color'] }}" stroke-width="22" stroke-dasharray="{{ round(max($len - 1.5, 0.5), 2) }} {{ round($circ - $len + 1.5, 2) }}" stroke-dashoffset="{{ -round($offset, 2) }}" />
                                @php $offset += $len; @endphp
                            @endforeach
                            <g class="rotate-90" style="transform-origin: 80px 80px">
                                <text x="80" y="80" text-anchor="middle" font-size="22" font-weight="700" fill="#111827">{{ $occTotal }}</text>
                                <text x="80" y="97" text-anchor="middle" font-size="9" fill="#6b7280">Total Seats</text>
                            </g>
                        </svg>
                        <ul class="flex-1 space-y-2.5 text-[11px] text-gray-600">
                            @foreach ($occ as $seg)
                                <li class="flex items-center gap-2"><span class="h-2 w-2 rounded-full" style="background: {{ $seg['color'] }}"></span><span class="flex-1 truncate">{{ $seg['label'] }}</span>
                                    <span class="font-medium text-gray-700">{{ $seg['value'] }} <span class="text-gray-400">({{ round($seg['value'] / $occTotal * 100, 1) }}%)</span></span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        <div class="rounded-2xl border border-gray-200 border-t-4 border-t-emerald-500 bg-white p-5 shadow-sm xl:col-span-3">
            <h3 class="mb-3 text-base font-extrabold text-gray-900">Recent Activities</h3>
            <ul class="space-y-3.5">
                @forelse ($this->activity as $log)
                    <li class="flex items-start gap-3" wire:key="act-{{ $log->id }}">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-brand-600"><x-lm.icon name="check-circle" class="h-4 w-4" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[11px] font-semibold text-gray-900">{{ $nice($log->action) }}</p>
                            <p class="truncate text-[11px] text-gray-500">{{ $log->actor?->name ?? 'System' }}</p>
                        </div>
                        <span class="shrink-0 text-[10px] text-gray-400">{{ $log->created_at->diffForHumans(short: true) }}</span>
                    </li>
                @empty
                    <li class="py-6 text-center text-xs text-gray-400">Activity will appear here as your team works.</li>
                @endforelse
            </ul>
        </div>
    </div>

    {{-- tables --}}
    <div class="grid gap-4 xl:grid-cols-12">
        @if ($can('memberships.view'))
            <div class="rounded-2xl border border-gray-200 border-t-4 border-t-orange-500 bg-white p-5 shadow-sm xl:col-span-5">
                <div class="mb-3 flex items-center justify-between"><h3 class="text-base font-extrabold text-gray-900">{{ $this->period['current'] ? 'Expiring Memberships' : 'Memberships started in '.$this->period['label'] }}</h3><a href="{{ route('app.memberships.index', ['library' => $slug, 'state' => 'active']) }}" wire:navigate class="text-xs font-bold text-brand-700 hover:text-brand-800">View all</a></div>
                <table class="w-full text-[11px]">
                    <thead><tr><th class="{{ $th }}">Student</th><th class="{{ $th }}">Plan</th>@if ($this->period['current'])<th class="{{ $th }}">Expires On</th><th class="{{ $th }} text-right">Days Left</th>@else<th class="{{ $th }}">Started</th><th class="{{ $th }} text-right">Charge</th>@endif</tr></thead>
                    <tbody class="text-gray-700">
                        @forelse ($this->expiring as $m)
                            @php $days = (int) today()->diffInDays($m->ends_on, false); @endphp
                            <tr class="border-t border-gray-100" wire:key="exp-{{ $m->id }}"><td class="py-2 font-medium">{{ $m->student->name }}</td><td class="py-2 text-gray-500">{{ $m->plan->name }}</td>
                                @if ($this->period['current'])
                                    <td class="py-2">{{ $m->ends_on->format('d M Y') }}</td><td class="py-2 text-right font-semibold {{ $days <= 3 ? 'text-red-500' : 'text-orange-500' }}">{{ $days }}</td>
                                @else
                                    <td class="py-2">{{ $m->starts_on->format('d M Y') }}</td><td class="py-2 text-right font-semibold">₹{{ number_format((float) $m->charge, 2) }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ $this->period['current'] ? 'Nothing expires in the next 14 days.' : 'No memberships started in this year.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        @if ($can('students.view'))
            <div class="rounded-2xl border border-gray-200 border-t-4 border-t-emerald-500 bg-white p-5 shadow-sm xl:col-span-4">
                <div class="mb-3 flex items-center justify-between"><h3 class="text-base font-extrabold text-gray-900">New Students</h3><a href="{{ route('app.students.index', ['library' => $slug]) }}" wire:navigate class="text-xs font-bold text-brand-700 hover:text-brand-800">View all</a></div>
                <table class="w-full text-[11px]">
                    <thead><tr><th class="{{ $th }}" colspan="2">Name</th><th class="{{ $th }}">Phone</th><th class="{{ $th }}">Joined On</th></tr></thead>
                    <tbody class="text-gray-700">
                        @forelse ($this->newStudents as $s)
                            <tr class="border-t border-gray-100" wire:key="ns-{{ $s->id }}">
                                <td class="w-8 py-2"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-100 text-[10px] font-semibold text-brand-700">{{ mb_strtoupper(mb_substr($s->name, 0, 1)) }}</span></td>
                                <td class="py-2 font-medium">{{ $s->name }}</td><td class="py-2 text-gray-500">{{ $s->phone }}</td><td class="py-2">{{ $s->registered_on->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">No students yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        @if ($can('payments.view'))
            <div class="rounded-2xl border border-gray-200 border-t-4 border-t-teal-500 bg-white p-5 shadow-sm xl:col-span-3">
                <div class="mb-3 flex items-center justify-between"><h3 class="text-base font-extrabold text-gray-900">Collections by Method</h3><span class="shrink-0 whitespace-nowrap rounded-lg border border-gray-200 px-2.5 py-1 text-[11px] text-gray-600">{{ $this->period['label'] }}</span></div>
                <ul class="space-y-3">
                    @forelse ($methods as $m)
                        <li><div class="mb-1 flex items-center justify-between text-[11px]"><span class="text-gray-700">{{ $m['label'] }}</span><span class="font-medium text-gray-900">₹{{ number_format($m['value'], 2) }}</span></div>
                            <div class="h-2 rounded-full bg-gray-100"><div class="h-2 rounded-full bg-gradient-to-r from-emerald-500 to-teal-500" style="width: {{ round($m['value'] / $methodMax * 100) }}%"></div></div></li>
                    @empty
                        <li class="py-6 text-center text-xs text-gray-400">No payments in {{ $this->period['label'] }}.</li>
                    @endforelse
                </ul>
                <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-3 text-[11px]"><span class="font-medium text-gray-700">Total Collection</span><span class="text-lg font-extrabold text-emerald-600">₹{{ number_format($methodTotal, 2) }}</span></div>
            </div>
        @endif
    </div>

    <div class="grid gap-4 xl:grid-cols-12">
        @if ($can('payments.view'))
            <div class="rounded-2xl border border-gray-200 border-t-4 border-t-violet-500 bg-white p-5 shadow-sm xl:col-span-9">
                <div class="mb-3 flex items-center justify-between"><h3 class="text-base font-extrabold text-gray-900">Recent Payments</h3><a href="{{ route('app.payments.index', ['library' => $slug]) }}" wire:navigate class="text-xs font-bold text-brand-700 hover:text-brand-800">View all</a></div>
                <table class="w-full text-[11px]">
                    <thead><tr>@foreach (['Student', 'Receipt No.', 'Amount', 'Method', 'Date', 'Status'] as $col)<th class="{{ $th }}">{{ $col }}</th>@endforeach</tr></thead>
                    <tbody class="text-gray-700">
                        @forelse ($this->recentPayments as $p)
                            <tr class="border-t border-gray-100" wire:key="rp-{{ $p->id }}">
                                <td class="py-2 font-medium">{{ $p->student->name }}</td><td class="py-2 text-gray-500">{{ $p->receipt_no }}</td>
                                <td class="py-2 font-semibold text-gray-900">₹{{ number_format((float) $p->amount, 2) }}</td><td class="py-2">{{ \App\Models\Payment::METHODS[$p->method] ?? $p->method }}</td>
                                <td class="py-2">{{ $p->paid_on->format('d M Y') }}</td><td class="py-2"><x-ui.badge :color="$p->isReversed() ? 'red' : 'green'">{{ $p->isReversed() ? 'Reversed' : 'Completed' }}</x-ui.badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">No payments recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        <div class="rounded-2xl border border-gray-200 border-t-4 border-t-amber-500 bg-white p-5 shadow-sm {{ $can('payments.view') ? 'xl:col-span-3' : 'xl:col-span-12' }}">
            <h3 class="mb-3 text-base font-extrabold text-gray-900">Quick Actions</h3>
            <div class="grid grid-cols-2 gap-3">
                @foreach ([
                    ['Add Student', 'users', 'green', 'app.students.create', 'students.create'],
                    ['Assign Membership', 'badge', 'blue', 'app.memberships.index', 'memberships.manage'],
                    ['Record Payment', 'cash', 'orange', 'app.payments.index', 'payments.record'],
                    ['Attendance', 'clock', 'purple', 'app.attendance.index', 'attendance.record'],
                    ['Seats', 'seat', 'purple', 'app.seats.index', 'seats.view'],
                    ['Add Expense', 'receipt', 'red', 'app.expenses.index', 'expenses.manage'],
                ] as [$label, $icon, $tone, $route, $perm])
                    @if ($can($perm))
                        @php $qg = ['green' => 'from-emerald-600 to-teal-500', 'blue' => 'from-sky-600 to-blue-500', 'orange' => 'from-orange-500 to-amber-400', 'purple' => 'from-violet-600 to-purple-500', 'red' => 'from-red-600 to-rose-500'][$tone] ?? 'from-slate-600 to-gray-500'; @endphp
                        <a href="{{ route($route, ['library' => $slug]) }}" wire:navigate class="relative flex flex-col items-center gap-2 overflow-hidden rounded-2xl bg-gradient-to-br {{ $qg }} px-2 py-4 text-center text-xs font-bold text-white shadow-md transition hover:-translate-y-0.5 hover:shadow-lg">
                            <span class="pointer-events-none absolute -bottom-6 -right-5 h-16 w-16 rounded-full bg-white/15"></span>
                            <span class="relative flex h-10 w-10 items-center justify-center rounded-xl bg-white/25"><x-lm.icon :name="$icon" class="h-5 w-5" /></span><span class="relative">{{ $label }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
</div>
