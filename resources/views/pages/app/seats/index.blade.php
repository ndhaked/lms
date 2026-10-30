<?php

use App\Actions\AllocateSeat;
use App\Actions\ReleaseSeat;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Seat;
use App\Models\Student;
use App\Models\StudyHall;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithPagination;

    #[Url]
    public string $view = 'table'; // table | map

    // which shift the seat map and counts describe (a seat can be shared by students in different shifts)
    #[Url]
    public string $shift = 'morning';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $hall = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    // seat create / edit
    public bool $showSeat = false;
    public ?int $editingId = null;
    public string $s_hall = '';
    public string $s_code = '';
    public string $s_type = 'standard';
    public string $s_status = 'available';
    public string $s_notes = '';

    // bulk generate
    public bool $showGenerate = false;
    public string $g_hall = '';
    public string $g_prefix = 'A-';
    public string $g_start = '1';
    public string $g_count = '10';
    public string $g_type = 'standard';

    // allocate
    public ?int $allocatingSeat = null;
    public string $studentSearch = '';
    public ?int $chosenStudent = null;
    public string $allocShift = 'fullday';

    public function mount(): void
    {
        $this->authorizePermission('seats.view');
        $this->g_prefix = (string) \App\Support\LibrarySettings::get('seat_prefix');
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'hall', 'status', 'type'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function halls()
    {
        return StudyHall::orderBy('name')->get(['id', 'name']);
    }

    protected function query()
    {
        return Seat::query()
            ->with(['hall', 'activeAllocations.student'])
            ->when($this->search !== '', fn ($q) => $q->where('code', 'like', "%{$this->search}%"))
            ->when($this->hall !== '', fn ($q) => $q->where('study_hall_id', $this->hall))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->orderBy('code');
    }

    #[Computed]
    public function seats()
    {
        return $this->query()->paginate(25);
    }

    #[Computed]
    public function mapSeats()
    {
        return $this->query()->limit(600)->get();
    }

    public function currentShift(): string
    {
        return isset(\App\Models\SeatAllocation::SHIFTS[$this->shift]) ? $this->shift : 'morning';
    }

    #[Computed]
    public function counts(): array
    {
        // counted for the chosen shift: a seat with only a morning student is still available in the evening
        return Seat::query()->with('activeAllocations')->get(['id', 'status'])->countBy(fn ($s) => $s->stateFor($this->currentShift()))->all();
    }

    /** Active students with a running membership and no current seat. */
    #[Computed]
    public function eligible()
    {
        return Student::query()
            ->where('status', Student::ACTIVE)
            ->whereDoesntHave('activeAllocation')
            ->whereHas('memberships', fn ($m) => $m->current())
            ->when($this->studentSearch !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->studentSearch}%")
                ->orWhere('phone', 'like', "%{$this->studentSearch}%")
                ->orWhere('student_code', 'like', "%{$this->studentSearch}%")))
            ->orderBy('name')->limit(8)->get(['id', 'name', 'phone', 'student_code']);
    }

    // ---- seat form ----
    public function createSeat(): void
    {
        $this->authorizePermission('seats.manage');
        $this->reset(['editingId', 's_hall', 's_code', 's_notes']);
        $this->fill(['s_type' => 'standard', 's_status' => 'available']);
        $this->resetErrorBag();
        $this->showSeat = true;
    }

    public function editSeat(int $id): void
    {
        $this->authorizePermission('seats.manage');
        $seat = Seat::findOrFail($id);

        $this->editingId = $seat->id;
        $this->fill(['s_hall' => (string) $seat->study_hall_id, 's_code' => $seat->code, 's_type' => $seat->type, 's_status' => $seat->status, 's_notes' => (string) $seat->notes]);
        $this->resetErrorBag();
        $this->showSeat = true;
    }

    public function saveSeat(): void
    {
        $this->authorizePermission('seats.manage');

        $current = $this->editingId ? Seat::findOrFail($this->editingId) : null;
        $locked = $current?->status === Seat::OCCUPIED; // occupancy is controlled by allocation only

        $data = $this->validate([
            's_hall' => ['required', Rule::exists('study_halls', 'id')->where('library_id', Tenancy::currentId())],
            's_code' => ['required', 'string', 'max:30', Rule::unique('seats', 'code')->where('library_id', Tenancy::currentId())->ignore($this->editingId)],
            's_type' => ['required', Rule::in(array_keys(Seat::TYPES))],
            's_status' => $locked ? ['nullable'] : ['required', Rule::in(array_diff(array_keys(Seat::STATUSES), [Seat::OCCUPIED]))],
            's_notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['s_hall' => 'hall', 's_code' => 'seat number', 's_type' => 'type', 's_status' => 'status']);

        $payload = [
            'study_hall_id' => $data['s_hall'], 'code' => $data['s_code'], 'type' => $data['s_type'],
            'status' => $locked ? Seat::OCCUPIED : $data['s_status'], 'notes' => $data['s_notes'] ?: null,
        ];

        if (! $current) {
            try {
                \App\Support\Entitlements::assertCanAdd('seats');   // plan limit
            } catch (\App\Exceptions\BusinessRuleException $e) {
                $this->addError('s_code', $e->getMessage());

                return;
            }
        }

        $current ? $current->update($payload) : Seat::create($payload);

        $this->showSeat = false;
        $this->refreshLists();
        $this->dispatch('notify', message: 'Seat saved.');
    }

    // ---- generate ----
    public function openGenerate(): void
    {
        $this->authorizePermission('seats.manage');
        $this->resetErrorBag();
        $this->showGenerate = true;
    }

    public function generate(): void
    {
        $this->authorizePermission('seats.manage');

        $data = $this->validate([
            'g_hall' => ['required', Rule::exists('study_halls', 'id')->where('library_id', Tenancy::currentId())],
            'g_prefix' => ['nullable', 'string', 'max:10'],
            'g_start' => ['required', 'integer', 'min:1', 'max:99999'],
            'g_count' => ['required', 'integer', 'min:1', 'max:500'],
            'g_type' => ['required', Rule::in(array_keys(Seat::TYPES))],
        ]);

        // Work out which numbers are actually new, so the plan limit is checked against what will be created.
        $codes = [];
        for ($n = (int) $data['g_start']; $n < (int) $data['g_start'] + (int) $data['g_count']; $n++) {
            $code = ($data['g_prefix'] ?? '').str_pad((string) $n, (int) \App\Support\LibrarySettings::get('seat_padding'), '0', STR_PAD_LEFT);
            if (! Seat::where('code', $code)->exists()) {
                $codes[] = $code;
            }
        }

        try {
            \App\Support\Entitlements::assertCanAdd('seats', count($codes));
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->addError('g_count', $e->getMessage());

            return;
        }

        $created = 0;
        DB::transaction(function () use ($data, $codes, &$created) {
            foreach ($codes as $code) {
                Seat::create(['study_hall_id' => $data['g_hall'], 'code' => $code, 'type' => $data['g_type']]);
                $created++;
            }
        });

        $this->showGenerate = false;
        $this->refreshLists();
        $this->dispatch('notify', message: "{$created} seat(s) created".($created < (int) $data['g_count'] ? ' (existing numbers were skipped).' : '.'));
    }

    // ---- allocate / release ----
    public function startAllocate(int $seatId): void
    {
        $this->authorizePermission('seats.allocate');
        $this->allocatingSeat = Seat::findOrFail($seatId)->id;
        $this->reset(['studentSearch', 'chosenStudent']);
        $this->allocShift = $this->currentShift();
    }

    public function cancelAllocate(): void
    {
        $this->allocatingSeat = null;
    }

    public function allocate(): void
    {
        $this->authorizePermission('seats.allocate');
        $this->validate(['chosenStudent' => ['required', 'integer']], ['chosenStudent.required' => 'Choose a student.']);

        try {
            $seat = Seat::findOrFail($this->allocatingSeat);
            app(AllocateSeat::class)($seat, Student::findOrFail($this->chosenStudent), $this->allocShift);
        } catch (BusinessRuleException $e) {
            $this->addError('chosenStudent', $e->getMessage());

            return;
        }

        $this->allocatingSeat = null;
        $this->refreshLists();
        $this->dispatch('notify', message: "Seat {$seat->code} allocated.");
    }

    public function release(int $seatId): void
    {
        $this->authorizePermission('seats.allocate');

        $seat = Seat::with('activeAllocations')->findOrFail($seatId);
        try {
            if ($holder = $seat->holderFor($this->currentShift())) {
                app(ReleaseSeat::class)($holder);
            }
        } catch (BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        $this->refreshLists();
        $this->dispatch('notify', message: "Seat {$seat->code} released.");
    }

    public function closeModal(): void
    {
        $this->showSeat = false;
        $this->showGenerate = false;
    }

    protected function refreshLists(): void
    {
        unset($this->seats, $this->mapSeats, $this->counts);
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Seats" subtitle="Allocate, release and organise seats" /></x-slot:header>

    @php
        $statusColor = ['available' => 'green', 'occupied' => 'blue', 'reserved' => 'amber', 'unavailable' => 'gray', 'maintenance' => 'red'];
        $tile = ['available' => 'border-gray-200 [&>span:last-child]:bg-emerald-50 [&>span:last-child]:text-emerald-700', 'occupied' => 'border-orange-200 bg-orange-50/40', 'reserved' => 'border-amber-300 [&>span:last-child]:bg-amber-50 [&>span:last-child]:text-amber-700', 'unavailable' => 'border-gray-200 opacity-70 [&>span:last-child]:text-gray-500', 'maintenance' => 'border-red-200 [&>span:last-child]:bg-red-50 [&>span:last-child]:text-red-600'];
        $canAllocate = $this->can('seats.allocate');
        $canManage = $this->can('seats.manage');
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Seat number…" class="w-40 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <select wire:model.live="hall" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">All halls</option>
                @foreach ($this->halls as $h) <option value="{{ $h->id }}">{{ $h->name }}</option> @endforeach
            </select>
            <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">Any status</option>
                @foreach (\App\Models\Seat::STATUSES as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach
            </select>
            <select wire:model.live="type" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">Any type</option>
                @foreach (\App\Models\Seat::TYPES as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach
            </select>
            <div class="inline-flex overflow-hidden rounded-lg border border-gray-300 text-sm">
                <button wire:click="$set('view', 'table')" class="px-3 py-2 {{ $view === 'table' ? 'bg-brand-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50' }}">Table</button>
                <button wire:click="$set('view', 'map')" class="px-3 py-2 {{ $view === 'map' ? 'bg-brand-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50' }}">Seat map</button>
            </div>
        </div>
        @if ($canManage)
            <div class="flex gap-2">
                <x-ui.button variant="secondary" wire:click="openGenerate"><x-lm.icon name="squares-plus" class="h-4 w-4" /> Generate seats</x-ui.button>
                <x-ui.button wire:click="createSeat"><x-lm.icon name="plus" class="h-4 w-4" /> Add seat</x-ui.button>
            </div>
        @endif
    </div>

    <div class="flex flex-wrap gap-4 text-xs text-gray-600">
        @foreach (\App\Models\Seat::STATUSES as $k => $l)
            <span class="flex items-center gap-1.5"><x-ui.badge :color="$statusColor[$k]">{{ $this->counts[$k] ?? 0 }}</x-ui.badge> {{ $l }}</span>
        @endforeach
    </div>

    @php
        $shift = $this->currentShift();
        $shiftIcon = ['morning' => 'M12 3v2m0 14v2M5 12H3m18 0h-2M6.3 6.3 4.9 4.9m14.2 14.2-1.4-1.4M6.3 17.7l-1.4 1.4m14.2-14.2-1.4 1.4M12 8a4 4 0 100 8 4 4 0 000-8z', 'afternoon' => 'M12 3v2m0 14v2M5 12H3m18 0h-2M12 8a4 4 0 100 8 4 4 0 000-8z', 'evening' => 'M3 17h18M7 17a5 5 0 0110 0M12 7v2M5.6 10.6l1.4 1.4M18.4 10.6 17 12', 'night' => 'M21 12.8A9 9 0 1111.2 3 7 7 0 0021 12.8z', 'fullday' => 'M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z'];
    @endphp
    <div class="flex gap-1 overflow-x-auto rounded-xl border border-gray-200 bg-white p-1.5 shadow-sm">
        @foreach (\App\Models\SeatAllocation::SHIFTS as $k => $l)
            <button type="button" wire:click="$set('shift', '{{ $k }}')" wire:key="shift-{{ $k }}" class="flex min-w-[7rem] flex-1 items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-bold {{ $shift === $k ? 'bg-gradient-to-r from-amber-400 to-orange-500 text-white shadow' : 'text-gray-700 hover:bg-gray-50' }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $shiftIcon[$k] }}"/></svg>{{ $l }}
            </button>
        @endforeach
    </div>

    @if ($view === 'map')
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            @if ($this->mapSeats->isEmpty())
                <p class="py-8 text-center text-sm text-gray-400">No seats match.</p>
            @else
                @foreach ($this->mapSeats->groupBy(fn ($s) => $s->hall?->name ?? 'No hall') as $hallName => $group)
                    <h4 class="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-gray-400 first:mt-0">{{ $hallName }}</h4>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($group as $seat)
                            <div wire:key="map-{{ $seat->id }}" x-data="{ open: false }" class="relative w-[5.5rem]">
                                @php $state = $seat->stateFor($shift); $art = in_array($state, ['available', 'occupied', 'reserved'], true) ? $state : 'blocked'; $allot = $seat->holderFor($shift); $holder = $allot?->student; @endphp
                                <button type="button" x-on:click="open = ! open" title="{{ $seat->code }} · {{ \App\Models\Seat::STATUSES[$state] ?? $state }}{{ $holder ? ' · '.$holder->name.' ('.(\App\Models\SeatAllocation::SHIFTS[$allot->shift] ?? '').')' : '' }}" class="relative flex w-full flex-col items-center overflow-hidden rounded-xl border bg-white pt-5 text-center {{ $tile[$state] }}">
                                    <span class="absolute left-1 top-1 rounded px-1 text-[10px] font-bold leading-4 text-white {{ ['available' => 'bg-emerald-500', 'occupied' => 'bg-red-500', 'reserved' => 'bg-amber-500'][$state] ?? 'bg-gray-500' }}">{{ $seat->code }}</span>
                                    @if ($holder)<span class="absolute right-1 top-1 rounded bg-gray-100 px-1 text-[10px] font-bold leading-4 text-gray-600">#{{ (int) \Illuminate\Support\Str::afterLast($holder->student_code, '-') }}</span>@endif
                                    <x-lm.seat-art :state="$art" />
                                    <span class="mt-1 w-full truncate px-1 py-1 text-[11px] font-bold {{ $holder ? 'bg-orange-50 text-orange-600' : 'bg-black/5' }}">{{ $holder?->name ?? (\App\Models\Seat::STATUSES[$state] ?? ucfirst($state)) }}</span>
                                </button>
                                <div x-show="open" x-cloak x-on:click.outside="open = false" class="absolute left-0 top-full z-20 mt-1 w-32 rounded-lg border border-gray-200 bg-white py-1 text-xs shadow-lg">
                                    @if ($canAllocate && $state === 'available') <button wire:click="startAllocate({{ $seat->id }})" x-on:click="open = false" class="block w-full px-3 py-1.5 text-left hover:bg-gray-50">Allocate</button> @endif
                                    @if ($canAllocate && $state === 'occupied') <button wire:click="release({{ $seat->id }})" wire:confirm="Release seat {{ $seat->code }} ({{ \App\Models\SeatAllocation::SHIFTS[$allot->shift] }})?" x-on:click="open = false" class="block w-full px-3 py-1.5 text-left hover:bg-gray-50">Release</button> @endif
                                    @if ($canManage) <button wire:click="editSeat({{ $seat->id }})" x-on:click="open = false" class="block w-full px-3 py-1.5 text-left hover:bg-gray-50">Edit</button> @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @endif
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Seat</th><th class="px-4 py-3 font-medium">Hall / floor</th><th class="px-4 py-3 font-medium">Type</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3 font-medium">Student</th><th class="px-4 py-3 text-right font-medium">Actions</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($this->seats as $seat)
                            <tr wire:key="seat-{{ $seat->id }}">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $seat->code }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $seat->hall?->name }}</td>
                                <td class="px-4 py-3"><x-ui.badge>{{ \App\Models\Seat::TYPES[$seat->type] ?? $seat->type }}</x-ui.badge></td>
                                <td class="px-4 py-3"><x-ui.badge :color="$statusColor[$seat->status] ?? 'gray'">{{ \App\Models\Seat::STATUSES[$seat->status] }}</x-ui.badge></td>
                                <td class="px-4 py-3 text-gray-700">@forelse ($seat->activeAllocations as $al)<div>{{ $al->student?->name }} <span class="text-xs text-gray-400">{{ \App\Models\SeatAllocation::SHIFTS[$al->shift] ?? '' }}</span></div>@empty — @endforelse</td>
                                <td class="space-x-3 px-4 py-3 text-right">
                                    @if ($canAllocate && $seat->isFreeFor($shift)) <button wire:click="startAllocate({{ $seat->id }})" class="font-medium text-brand-600 hover:text-brand-800">Allocate</button> @endif
                                    @if ($canAllocate && $seat->stateFor($shift) === 'occupied') <button wire:click="release({{ $seat->id }})" wire:confirm="Release seat {{ $seat->code }}?" class="font-medium text-amber-600 hover:text-amber-800">Release</button> @endif
                                    @if ($canManage) <button wire:click="editSeat({{ $seat->id }})" class="font-medium text-gray-500 hover:text-gray-800">Edit</button> @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">No seats yet. Add a hall first, then generate its seats.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($this->seats->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->seats->links() }}</div> @endif
        </div>
    @endif

    {{-- add / edit seat --}}
    @if ($showSeat)
        <x-ui.modal :title="$editingId ? 'Edit seat' : 'Add seat'" max="max-w-xl" wire:key="seat-modal">
            <form wire:submit="saveSeat" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select name="s_hall" label="Hall / floor" required wire:model="s_hall">
                        <option value="">Choose…</option>
                        @foreach ($this->halls as $h) <option value="{{ $h->id }}">{{ $h->name }}</option> @endforeach
                    </x-ui.select>
                    <x-ui.input name="s_code" label="Seat number" required wire:model="s_code" />
                    <x-ui.select name="s_type" label="Type" wire:model="s_type">@foreach (\App\Models\Seat::TYPES as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach</x-ui.select>
                    <x-ui.select name="s_status" label="Status" wire:model="s_status" :disabled="$s_status === 'occupied'" hint="Occupied is set by allocating a seat, never by hand.">
                        @foreach (\App\Models\Seat::STATUSES as $k => $l) @if ($k !== 'occupied' || $s_status === 'occupied') <option value="{{ $k }}">{{ $l }}</option> @endif @endforeach
                    </x-ui.select>
                </div>
                <x-ui.textarea name="s_notes" label="Notes" wire:model="s_notes" />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Save</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    {{-- generate --}}
    @if ($showGenerate)
        <x-ui.modal title="Generate seats" max="max-w-xl" wire:key="gen-modal">
            <form wire:submit="generate" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select name="g_hall" label="Hall / floor" required wire:model="g_hall">
                        <option value="">Choose…</option>
                        @foreach ($this->halls as $h) <option value="{{ $h->id }}">{{ $h->name }}</option> @endforeach
                    </x-ui.select>
                    <x-ui.select name="g_type" label="Type" wire:model="g_type">@foreach (\App\Models\Seat::TYPES as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach</x-ui.select>
                    <x-ui.input name="g_prefix" label="Prefix" wire:model="g_prefix" hint='"A-" gives A-01, A-02 …' />
                    <x-ui.input name="g_start" label="Start number" type="number" min="1" wire:model="g_start" />
                    <x-ui.input name="g_count" label="How many" type="number" min="1" max="500" wire:model="g_count" />
                </div>
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Generate</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    {{-- allocate --}}
    @if ($allocatingSeat)
        <x-ui.modal title="Allocate seat" close="cancelAllocate()" max="max-w-xl" wire:key="alloc-modal">
            <div class="mb-3"><x-ui.select name="allocShift" label="Shift" wire:model="allocShift">@foreach (\App\Models\SeatAllocation::SHIFTS as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach</x-ui.select></div>
            <p class="mb-3 text-xs text-gray-500">Only active students with a running membership and no seat are listed.</p>
            <input wire:model.live.debounce.250ms="studentSearch" type="search" placeholder="Search name, phone or student ID…" class="mb-3 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500" autofocus>
            <ul class="max-h-64 space-y-1 overflow-y-auto">
                @forelse ($this->eligible as $st)
                    <li wire:key="el-{{ $st->id }}">
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm {{ $chosenStudent === $st->id ? 'border-brand-400 bg-brand-50' : 'border-gray-200 hover:bg-gray-50' }}">
                            <input type="radio" wire:model.live="chosenStudent" value="{{ $st->id }}" class="text-brand-600 focus:ring-brand-500">
                            <span class="flex-1 font-medium text-gray-900">{{ $st->name }}</span>
                            <span class="text-xs text-gray-400">{{ $st->student_code }} · {{ $st->phone }}</span>
                        </label>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-gray-400">No eligible students.</li>
                @endforelse
            </ul>
            @error('chosenStudent') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            <div class="mt-4 flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="cancelAllocate">Cancel</x-ui.button><x-ui.button wire:click="allocate">Allocate</x-ui.button></div>
        </x-ui.modal>
    @endif
</div>
