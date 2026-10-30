<?php

use App\Actions\ReleaseSeat;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\StudentMembership;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $membership = '';

    /** Registration date range (either end may be left empty). */
    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    /** vip | frozen: the quick views in the sidebar (blocked and left are the status filter) */
    #[Url]
    public string $view = '';

    public function mount(): void
    {
        $this->authorizePermission('students.view');
    }

    /** Quick ranges: today | week | month | last30 | clear */
    public function range(string $preset): void
    {
        [$this->from, $this->until] = match ($preset) {
            'today' => [today()->toDateString(), today()->toDateString()],
            'week' => [today()->startOfWeek()->toDateString(), today()->toDateString()],
            'month' => [today()->startOfMonth()->toDateString(), today()->toDateString()],
            'last30' => [today()->subDays(29)->toDateString(), today()->toDateString()],
            default => ['', ''],
        };
        $this->resetPage();
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'status', 'membership', 'view', 'from', 'until'], true)) {
            foreach (['from', 'until'] as $k) {
                if ($this->{$k} !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->{$k})) {
                    $this->{$k} = '';
                }
            }
            $this->resetPage();
        }
    }

    #[Computed]
    public function students()
    {
        return Student::query()
            ->with(['activeAllocation.seat', 'latestAllocation.seat'])
            ->addSelect(['students.*', 'valid_until' => StudentMembership::query()
                ->select('ends_on')
                ->whereColumn('student_memberships.student_id', 'students.id')
                ->current()->orderByDesc('ends_on')->limit(1)])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhere('student_code', 'like', "%{$this->search}%")))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->from !== '', fn ($q) => $q->whereDate('registered_on', '>=', $this->from))
            ->when($this->until !== '', fn ($q) => $q->whereDate('registered_on', '<=', $this->until))
            ->when($this->view === 'vip', fn ($q) => $q->where('is_vip', true))
            ->when($this->view === 'frozen', fn ($q) => $q->whereHas('memberships', fn ($m) => $m->where('status', 'suspended')))
            ->when($this->membership === 'active', fn ($q) => $q->whereHas('memberships', fn ($m) => $m->current()))
            ->when($this->membership === 'none', fn ($q) => $q->whereDoesntHave('memberships', fn ($m) => $m->current()))
            ->when($this->membership === 'expiring', fn ($q) => $q->whereHas('memberships', fn ($m) => $m->expiringWithin((int) \App\Support\LibrarySettings::get('renewal_window_days'))))
            ->latest('id')
            ->paginate(15);
    }

    public function deactivate(int $id): void
    {
        $this->authorizePermission('students.deactivate');

        $student = Student::findOrFail($id); // tenant-scoped

        DB::transaction(function () use ($student) {
            if ($student->activeAllocation) {
                app(ReleaseSeat::class)($student->activeAllocation, 'student_deactivated');
            }
            $student->update(['status' => Student::INACTIVE]);
            AuditLog::record('student.deactivated', $student);
        });

        unset($this->students);
        $this->dispatch('notify', message: "{$student->name} deactivated. History is kept.");
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Students" subtitle="Everyone registered at this library" /></x-slot:header>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name, phone, ID…" class="w-64 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">All statuses</option>
                <option value="active">Active</option><option value="suspended">Blocked (suspended)</option><option value="inactive">Left (inactive)</option>
            </select>
            <select wire:model.live="view" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">All members</option><option value="vip">VIP members</option><option value="frozen">Frozen memberships</option>
            </select>
            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm shadow-sm" title="Filter by registration date">
                <span class="text-xs font-semibold text-gray-500">Registered</span>
                <input type="date" wire:model.live="from" max="{{ $until ?: today()->toDateString() }}" class="rounded border-gray-200 py-1 text-sm focus:border-brand-500 focus:ring-brand-500" aria-label="From date">
                <span class="text-gray-400">to</span>
                <input type="date" wire:model.live="until" min="{{ $from }}" class="rounded border-gray-200 py-1 text-sm focus:border-brand-500 focus:ring-brand-500" aria-label="To date">
                <span class="ml-1 flex flex-wrap gap-1">
                    @foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'last30' => 'Last 30 days'] as $k => $l)
                        <button type="button" wire:click="range('{{ $k }}')" class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-brand-50 hover:text-brand-700">{{ $l }}</button>
                    @endforeach
                    @if ($from !== '' || $until !== '')<button type="button" wire:click="range('clear')" class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-100">Clear</button>@endif
                </span>
            </div>
            <select wire:model.live="membership" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">Any membership</option>
                <option value="active">Has active membership</option><option value="none">No active membership</option><option value="expiring">Expiring soon</option>
            </select>
            <span wire:loading class="text-xs text-gray-400">Loading…</span>
        </div>
        @if ($this->can('students.export') && \App\Support\LibrarySettings::get('export_enabled') && \App\Support\Entitlements::hasModule('exports'))
            <div class="flex gap-2">
                <a href="{{ route('app.students.export', ['library' => \App\Support\Tenancy::slug(), 'format' => 'csv', 'status' => $status ?: null, 'from' => $from ?: null, 'until' => $until ?: null]) }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Export CSV</a>
                <a href="{{ route('app.students.export', ['library' => \App\Support\Tenancy::slug(), 'format' => 'xlsx', 'status' => $status ?: null, 'from' => $from ?: null, 'until' => $until ?: null]) }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Export Excel</a>
            </div>
        @endif
        @if ($this->can('students.create'))
            <a href="{{ route('app.students.create', ['library' => \App\Support\Tenancy::slug()]) }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">
                <x-lm.icon name="plus" class="h-4 w-4" /> Register student
            </a>
        @endif
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500">
                    <tr><th class="px-4 py-3 font-medium">ID</th><th class="px-4 py-3 font-medium">Name</th><th class="px-4 py-3 font-medium">Phone</th><th class="px-4 py-3 font-medium">Membership until</th><th class="px-4 py-3 font-medium">Seat</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3 text-right font-medium">Actions</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->students as $s)
                        <tr wire:key="student-{{ $s->id }}" class="hover:bg-gray-50/60">
                            <td class="px-4 py-3 text-gray-500">{{ $s->student_code }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900"><a href="{{ route('app.students.show', ['library' => \App\Support\Tenancy::slug(), 'student' => $s->id]) }}" wire:navigate class="hover:text-brand-700">{{ $s->name }}</a>@if ($s->is_vip) <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800">VIP</span>@endif</td>
                            <td class="px-4 py-3 text-gray-600">{{ $s->phone }}</td>
                            <td class="px-4 py-3">
                                @if ($s->valid_until)
                                    @php $until = \Illuminate\Support\Carbon::parse($s->valid_until); @endphp
                                    <span class="{{ $until->diffInDays(today()) <= 7 ? 'font-medium text-amber-600' : 'text-gray-700' }}">{{ $until->format('d M Y') }}</span>
                                @else
                                    <span class="text-gray-400">None</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                @if ($s->activeAllocation)
                                    {{ $s->activeAllocation->seat?->code }}
                                @elseif ($s->latestAllocation)
                                    <span class="text-gray-400" title="Last seat, released {{ $s->latestAllocation->released_at?->format('d M Y') }}">{{ $s->latestAllocation->seat?->code }}<span class="block text-[10px] leading-tight">released {{ $s->latestAllocation->released_at?->format('d M Y') }}</span></span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3"><x-ui.badge :color="match ($s->status) { 'active' => 'green', 'suspended' => 'amber', default => 'gray' }">{{ ucfirst($s->status) }}</x-ui.badge></td>
                            <td class="space-x-3 px-4 py-3 text-right">
                                <a href="{{ route('app.students.show', ['library' => \App\Support\Tenancy::slug(), 'student' => $s->id]) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-800">View</a>
                                @if ($this->can('students.update'))
                                    <a href="{{ route('app.students.edit', ['library' => \App\Support\Tenancy::slug(), 'student' => $s->id]) }}" wire:navigate class="font-medium text-gray-500 hover:text-gray-800">Edit</a>
                                @endif
                                @if ($s->status !== 'inactive' && $this->can('students.deactivate'))
                                    <button wire:click="deactivate({{ $s->id }})" wire:confirm="Deactivate {{ $s->name }}? Their history is kept and any seat is released." class="font-medium text-red-600 hover:text-red-800">Deactivate</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No students found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->students->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->students->links() }}</div> @endif
    </div>
</div>
