<?php

use App\Actions\CheckInStudent;
use App\Actions\CheckOutStudent;
use App\Actions\CorrectAttendance;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Attendance;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithPagination;

    public string $checkInSearch = '';

    // history filters
    #[Url]
    public string $date = '';

    #[Url(as: 'q')]
    public string $search = '';

    // correction
    public ?int $correctingId = null;
    public string $cIn = '';
    public string $cOut = '';
    public string $cReason = '';

    public function mount(): void
    {
        $this->authorizePermission('attendance.view');
        $this->date = $this->date ?: today()->toDateString();
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['date', 'search'], true)) {
            $this->resetPage();
        }
    }

    /** Everyone currently inside, oldest first. Visits from earlier days are flagged as missed check-outs. */
    #[Computed]
    public function inside()
    {
        return Attendance::with(['student', 'seat'])->whereNull('checked_out_at')->orderBy('checked_in_at')->get();
    }

    /** Students who may check in right now: active, running membership, not already inside. */
    #[Computed]
    public function candidates()
    {
        if (strlen(trim($this->checkInSearch)) < 2) {
            return collect();
        }

        return Student::query()
            ->where('status', Student::ACTIVE)
            ->whereHas('memberships', fn ($m) => $m->current())
            ->whereNotIn('id', Attendance::whereNull('checked_out_at')->select('student_id'))
            ->where(fn ($q) => $q->where('name', 'like', "%{$this->checkInSearch}%")->orWhere('phone', 'like', "%{$this->checkInSearch}%")->orWhere('student_code', 'like', "%{$this->checkInSearch}%"))
            ->with('activeAllocation.seat')
            ->orderBy('name')->limit(6)->get();
    }

    #[Computed]
    public function history()
    {
        return Attendance::with(['student', 'seat', 'checkedInBy', 'corrections'])
            ->when($this->date !== '', fn ($q) => $q->whereDate('checked_in_at', $this->date))
            ->when($this->search !== '', fn ($q) => $q->whereHas('student', fn ($s) => $s
                ->where('name', 'like', "%{$this->search}%")->orWhere('student_code', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%")))
            ->latest('checked_in_at')
            ->paginate(15);
    }

    #[Computed]
    public function visitsToday(): int
    {
        return Attendance::whereDate('checked_in_at', today())->count();
    }

    public function checkIn(int $studentId): void
    {
        $this->authorizePermission('attendance.record');

        try {
            $a = app(CheckInStudent::class)(Student::findOrFail($studentId));
        } catch (BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        $this->checkInSearch = '';
        $this->refreshLists();
        $this->dispatch('notify', message: "{$a->student->name} checked in.");
    }

    public function checkOut(int $attendanceId): void
    {
        $this->authorizePermission('attendance.record');

        try {
            $a = app(CheckOutStudent::class)(Attendance::findOrFail($attendanceId));
        } catch (BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        $this->refreshLists();
        $this->dispatch('notify', message: "{$a->student->name} checked out.");
    }

    public function startCorrect(int $id): void
    {
        $this->authorizePermission('attendance.correct');
        $a = Attendance::findOrFail($id);

        $this->correctingId = $a->id;
        $this->cIn = $a->checked_in_at->format('Y-m-d\TH:i');
        $this->cOut = $a->checked_out_at?->format('Y-m-d\TH:i') ?? '';
        $this->cReason = '';
        $this->resetErrorBag();
    }

    public function saveCorrect(): void
    {
        $this->authorizePermission('attendance.correct');
        $this->validate([
            'cIn' => ['required', 'date'],
            'cOut' => ['nullable', 'date'],
            'cReason' => ['required', 'string', 'max:500'],
        ], [], ['cIn' => 'check-in', 'cOut' => 'check-out', 'cReason' => 'reason']);

        try {
            app(CorrectAttendance::class)(
                Attendance::findOrFail($this->correctingId),
                Carbon::parse($this->cIn),
                $this->cOut !== '' ? Carbon::parse($this->cOut) : null,
                $this->cReason,
            );
        } catch (BusinessRuleException $e) {
            $this->addError('cReason', $e->getMessage());

            return;
        }

        $this->correctingId = null;
        $this->refreshLists();
        $this->dispatch('notify', message: 'Attendance corrected and logged.');
    }

    public function closeModal(): void
    {
        $this->correctingId = null;
    }

    protected function refreshLists(): void
    {
        unset($this->inside, $this->history, $this->visitsToday, $this->candidates);
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Attendance" subtitle="Check students in and out" /></x-slot:header>

    @php
        $canRecord = $this->can('attendance.record');
        $canCorrect = $this->can('attendance.correct');
    @endphp

    @unless (\App\Support\LibrarySettings::isWorkingDay(today()))
        @php $holiday = collect(\App\Support\LibrarySettings::get('holidays'))->firstWhere('date', today()->toDateString()); @endphp
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            The library is marked closed today{{ $holiday ? ' ('.$holiday['label'].')' : '' }}. Check-ins are still allowed, but double-check before recording.
        </div>
    @endunless

    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat label="Inside right now" :value="$this->inside->count()" icon="users" tone="green" />
        <x-ui.stat label="Visits today" :value="$this->visitsToday" icon="clock" tone="blue" />
        <x-ui.stat label="Missed check-outs" :value="$this->inside->filter->isStale()->count()" icon="alert" tone="red" hint="Visits from earlier days still open" />
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
        {{-- check in --}}
        @if ($canRecord)
            <section class="rounded-xl border border-gray-200 bg-white p-5">
                <h3 class="mb-3 text-sm font-semibold text-gray-900">Check in</h3>
                <input wire:model.live.debounce.250ms="checkInSearch" type="search" placeholder="Search name, phone or student ID…" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <ul class="mt-3 space-y-2">
                    @forelse ($this->candidates as $st)
                        <li wire:key="cand-{{ $st->id }}" class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <div><div class="font-medium text-gray-900">{{ $st->name }}</div><div class="text-xs text-gray-400">{{ $st->student_code }}@if ($st->activeAllocation?->seat) · seat {{ $st->activeAllocation->seat->code }}@endif</div></div>
                            <x-ui.button wire:click="checkIn({{ $st->id }})" class="px-3 py-1.5 text-xs">Check in</x-ui.button>
                        </li>
                    @empty
                        @if (strlen(trim($checkInSearch)) >= 2)
                            <li class="py-3 text-center text-xs text-gray-400">No matching student who can check in (needs an active membership and not already inside).</li>
                        @else
                            <li class="py-3 text-center text-xs text-gray-400">Type at least 2 letters to find a student.</li>
                        @endif
                    @endforelse
                </ul>
            </section>
        @endif

        {{-- inside now --}}
        <section class="rounded-xl border border-gray-200 bg-white p-5 {{ $canRecord ? 'xl:col-span-2' : 'xl:col-span-3' }}">
            <h3 class="mb-3 text-sm font-semibold text-gray-900">Currently inside</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="pb-2 font-medium">Student</th><th class="pb-2 font-medium">Seat</th><th class="pb-2 font-medium">Since</th><th class="pb-2 text-right font-medium"></th></tr></thead>
                    <tbody>
                        @forelse ($this->inside as $a)
                            <tr class="border-t border-gray-100" wire:key="in-{{ $a->id }}">
                                <td class="py-2"><span class="font-medium text-gray-900">{{ $a->student->name }}</span><span class="ml-2 text-xs text-gray-400">{{ $a->student->student_code }}</span></td>
                                <td class="py-2 text-gray-600">{{ $a->seat?->code ?? '—' }}</td>
                                <td class="py-2">
                                    {{ $a->checked_in_at->format($a->isStale() ? 'd M, H:i' : 'H:i') }}
                                    @if ($a->isStale()) <x-ui.badge color="red" class="ml-1">Missed check-out</x-ui.badge> @endif
                                </td>
                                <td class="space-x-3 py-2 text-right">
                                    @if ($a->isStale())
                                        @if ($canCorrect) <button wire:click="startCorrect({{ $a->id }})" class="font-medium text-red-600 hover:text-red-800">Close visit</button> @endif
                                    @elseif ($canRecord)
                                        <button wire:click="checkOut({{ $a->id }})" class="font-medium text-amber-600 hover:text-amber-800">Check out</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">Nobody is inside right now.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    {{-- history --}}
    <section class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h3 class="text-sm font-semibold text-gray-900">Attendance history</h3>
            <div class="flex flex-wrap items-end gap-3">
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Student…" class="w-48 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <label class="text-xs text-gray-500">Date <input type="date" wire:model.live="date" class="ml-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></label>
                @if ($date !== '') <button wire:click="$set('date', '')" class="text-xs font-medium text-gray-500 hover:text-gray-800">All dates</button> @endif
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Student</th><th class="px-4 py-3 font-medium">Seat</th><th class="px-4 py-3 font-medium">Check-in</th><th class="px-4 py-3 font-medium">Check-out</th><th class="px-4 py-3 font-medium">Duration</th><th class="px-4 py-3 font-medium">Recorded by</th><th class="px-4 py-3"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($this->history as $a)
                            <tr wire:key="h-{{ $a->id }}">
                                <td class="px-4 py-3"><div class="font-medium text-gray-900">{{ $a->student->name }}</div><div class="text-xs text-gray-400">{{ $a->student->student_code }}</div></td>
                                <td class="px-4 py-3 text-gray-600">{{ $a->seat?->code ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $a->checked_in_at->format('d M Y, H:i') }}</td>
                                <td class="px-4 py-3">{{ $a->checked_out_at?->format('d M Y, H:i') ?? 'Inside' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $a->minutes() !== null ? intdiv($a->minutes(), 60).'h '.($a->minutes() % 60).'m' : '—' }}</td>
                                <td class="px-4 py-3 text-xs text-gray-500">{{ $a->checkedInBy?->name ?? '—' }}@if ($a->corrections->isNotEmpty()) <x-ui.badge color="amber" class="ml-1" title="{{ $a->corrections->last()->reason }}">Corrected</x-ui.badge>@endif</td>
                                <td class="px-4 py-3 text-right">@if ($canCorrect)<button wire:click="startCorrect({{ $a->id }})" class="font-medium text-gray-500 hover:text-gray-800">Correct</button>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No attendance recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($this->history->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->history->links() }}</div> @endif
        </div>
    </section>

    @if ($correctingId)
        <x-ui.modal title="Correct attendance" max="max-w-lg" wire:key="correct-modal">
            <form wire:submit="saveCorrect" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="cIn" label="Check-in" type="datetime-local" required wire:model="cIn" />
                    <x-ui.input name="cOut" label="Check-out" type="datetime-local" wire:model="cOut" hint="Leave empty to keep the visit open." />
                </div>
                <x-ui.textarea name="cReason" label="Reason for correction" required wire:model="cReason" />
                <p class="text-xs text-gray-500">The original times and your reason are kept in the audit history.</p>
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Save correction</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
