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

    public function mount(): void
    {
        $this->authorizePermission('students.view');
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'status', 'membership'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function students()
    {
        return Student::query()
            ->with('activeAllocation.seat')
            ->addSelect(['students.*', 'valid_until' => StudentMembership::query()
                ->select('ends_on')
                ->whereColumn('student_memberships.student_id', 'students.id')
                ->current()->orderByDesc('ends_on')->limit(1)])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhere('student_code', 'like', "%{$this->search}%")))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
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
                <option value="active">Active</option><option value="suspended">Suspended</option><option value="inactive">Inactive</option>
            </select>
            <select wire:model.live="membership" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">Any membership</option>
                <option value="active">Has active membership</option><option value="none">No active membership</option><option value="expiring">Expiring soon</option>
            </select>
            <span wire:loading class="text-xs text-gray-400">Loading…</span>
        </div>
        @if ($this->can('students.export') && \App\Support\LibrarySettings::get('export_enabled') && \App\Support\Entitlements::hasModule('exports'))
            <div class="flex gap-2">
                <a href="{{ route('app.students.export', ['library' => \App\Support\Tenancy::slug(), 'format' => 'csv', 'status' => $status ?: null]) }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Export CSV</a>
                <a href="{{ route('app.students.export', ['library' => \App\Support\Tenancy::slug(), 'format' => 'xlsx', 'status' => $status ?: null]) }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Export Excel</a>
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
                            <td class="px-4 py-3 font-medium text-gray-900"><a href="{{ route('app.students.show', ['library' => \App\Support\Tenancy::slug(), 'student' => $s->id]) }}" wire:navigate class="hover:text-brand-700">{{ $s->name }}</a></td>
                            <td class="px-4 py-3 text-gray-600">{{ $s->phone }}</td>
                            <td class="px-4 py-3">
                                @if ($s->valid_until)
                                    @php $until = \Illuminate\Support\Carbon::parse($s->valid_until); @endphp
                                    <span class="{{ $until->diffInDays(today()) <= 7 ? 'font-medium text-amber-600' : 'text-gray-700' }}">{{ $until->format('d M Y') }}</span>
                                @else
                                    <span class="text-gray-400">None</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $s->activeAllocation?->seat?->code ?? '—' }}</td>
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
