<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Actions\AllocateSeat;
use App\Actions\AssignMembership;
use App\Actions\ReleaseSeat;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\MembershipPlan;
use App\Models\Seat;
use App\Models\Student;
use App\Models\StudyHall;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithFileUploads;

    public ?int $studentId = null;

    public string $name = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $emergency_contact_name = '';
    public string $emergency_contact_phone = '';
    public string $registered_on = '';
    public string $notes = '';
    public string $status = Student::ACTIVE;
    public string $member_type = 'student';
    public bool $is_vip = false;
    public string $biometric_id = '';
    public $photo = null;
    public ?string $existingPhoto = null;

    public bool $confirmDuplicate = false;

    // "Seat selection" pop-up: after registering, after updating a student who has no seat, or from the "Allot Seat" button
    public ?int $newStudentId = null;
    public bool $showSeat = false;
    public bool $seatAfterSave = false;     // opened by saving (Skip then goes to the profile) rather than by the button (Skip just closes)
    public ?int $pickSeat = null;
    public string $pickPlan = '';
    public string $pickStart = '';
    public string $hallFilter = '';
    public string $pickShift = 'fullday';

    /** May this person give a seat to this student now? Starting a plan is needed too when the student has no running membership. */
    protected function canSeatFor(Student $student): bool
    {
        if (! $this->can('seats.allocate') || ! Seat::exists() || $student->status !== Student::ACTIVE) {
            return false;
        }

        return $student->currentMembership() !== null || ($this->can('memberships.manage') && MembershipPlan::where('status', 'active')->exists());
    }

    #[\Livewire\Attributes\Computed]
    public function seatStudent(): ?Student
    {
        return $this->newStudentId ? Student::with('activeAllocation.seat.hall')->find($this->newStudentId) : null;
    }

    /** The student on this edit page, with the seat they hold now. */
    #[\Livewire\Attributes\Computed]
    public function editStudent(): ?Student
    {
        return $this->studentId ? Student::with('activeAllocation.seat.hall')->find($this->studentId) : null;
    }

    /** True when seating this student also has to start a membership. */
    #[\Livewire\Attributes\Computed]
    public function needsPlan(): bool
    {
        return $this->seatStudent && $this->seatStudent->currentMembership() === null;
    }

    #[\Livewire\Attributes\Computed]
    public function halls()
    {
        return StudyHall::orderBy('name')->get(['id', 'name']);
    }

    #[\Livewire\Attributes\Computed]
    public function plans()
    {
        return MembershipPlan::where('status', 'active')->orderBy('name')->get();
    }

    #[\Livewire\Attributes\Computed]
    public function seats()
    {
        return Seat::with(['activeAllocations.student:id,name,student_code'])
            ->when($this->hallFilter !== '', fn ($q) => $q->where('study_hall_id', (int) $this->hallFilter))
            ->orderBy('study_hall_id')->orderBy('code')->limit(400)->get();
    }

    protected function openSeats(Student $student, bool $afterSave): void
    {
        $this->newStudentId = $student->id;
        $this->seatAfterSave = $afterSave;
        $this->pickStart = today()->toDateString();
        $this->pickPlan = (string) (MembershipPlan::where('status', 'active')->orderBy('name')->value('id') ?? '');
        $this->pickSeat = null;
        $this->pickShift = 'fullday';
        $this->hallFilter = '';
        $this->resetErrorBag();
        $this->showSeat = true;
        unset($this->seatStudent, $this->needsPlan, $this->seats);
    }

    /** The "Allot Seat" button on the edit page. */
    public function openSeatPicker(): void
    {
        $student = Student::findOrFail($this->studentId);
        abort_unless($this->canSeatFor($student), 403);
        $this->openSeats($student, false);
    }

    public function selectSeat(int $id): void
    {
        $seat = Seat::with('activeAllocations')->find($id);
        $this->pickSeat = $seat && $seat->isFreeFor($this->pickShift) ? $seat->id : null;
        $this->resetErrorBag('pickSeat');
    }

    /** Skip / close. After saving it goes to the student's page; from the button it just closes the pop-up. */
    public function skipSeat()
    {
        $this->showSeat = false;

        return $this->seatAfterSave
            ? $this->redirect(route('app.students.show', ['library' => \App\Support\Tenancy::slug(), 'student' => $this->newStudentId]), navigate: true)
            : null;
    }

    /** Give the chosen seat (and start the chosen plan if the student has no running membership). If the seat cannot be given, nothing changes. */
    public function addSeat()
    {
        $student = Student::findOrFail($this->newStudentId);
        abort_unless($this->canSeatFor($student), 403);

        $needsPlan = $student->currentMembership() === null;
        $this->validate([
            'pickSeat' => ['required', 'integer'],
            'pickShift' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Models\SeatAllocation::SHIFTS))],
            'pickPlan' => [$needsPlan ? 'required' : 'nullable', 'integer'],
            'pickStart' => [$needsPlan ? 'required' : 'nullable', 'date'],
        ], ['pickSeat.required' => 'Choose a seat, or press Skip.', 'pickPlan.required' => 'Choose the membership plan the seat goes with.'], ['pickStart' => 'start date']);

        $seat = Seat::findOrFail($this->pickSeat);
        $plan = $needsPlan ? MembershipPlan::where('status', 'active')->findOrFail((int) $this->pickPlan) : null;
        $moved = $student->activeAllocation?->seat?->code;

        try {
            DB::transaction(function () use ($student, $plan, $seat, $needsPlan) {
                if ($old = $student->activeAllocation) {
                    app(ReleaseSeat::class)($old, 'seat_changed');
                }
                if ($needsPlan) {
                    app(AssignMembership::class)($student, $plan, \Illuminate\Support\Carbon::parse($this->pickStart));
                }
                app(AllocateSeat::class)($seat, $student, $this->pickShift);
            });
        } catch (BusinessRuleException $e) {
            $this->addError('pickSeat', $e->getMessage());
            $this->pickSeat = null;
            unset($this->seats);

            return;
        }

        session()->flash('notice', $this->seatAfterSave && ! $this->studentId
            ? "{$student->name} registered as {$student->student_code}, with seat {$seat->code} on the {$plan?->name} plan."
            : ($moved ? "{$student->name} moved from seat {$moved} to {$seat->code}." : "{$student->name} now has seat {$seat->code}".($plan ? " on the {$plan->name} plan." : '.')));
        $this->showSeat = false;

        return $this->redirect(route('app.students.show', ['library' => \App\Support\Tenancy::slug(), 'student' => $student->id]), navigate: true);
    }

    /** Take the seat away. The seat becomes free again and the history keeps it. */
    public function removeSeat()
    {
        abort_unless($this->can('seats.allocate'), 403);
        $student = Student::findOrFail($this->newStudentId ?? $this->studentId);
        $old = $student->activeAllocation;
        abort_unless($old, 404);

        app(ReleaseSeat::class)($old, 'seat_removed');
        session()->flash('notice', "Seat {$old->seat?->code} was taken back from {$student->name}.");
        $this->showSeat = false;

        return $this->redirect(route('app.students.show', ['library' => \App\Support\Tenancy::slug(), 'student' => $student->id]), navigate: true);
    }
    /** People who look like the one being registered (same name or email). The exact-phone case is a hard block elsewhere. */
    #[\Livewire\Attributes\Computed]
    public function duplicates()
    {
        if ($this->studentId || (mb_strlen(trim($this->name)) < 3 && trim($this->email) === '')) {
            return collect();
        }

        return Student::query()
            ->where(fn ($q) => $q
                ->when(mb_strlen(trim($this->name)) >= 3, fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower(trim($this->name))]))
                ->when(trim($this->email) !== '', fn ($q) => $q->orWhere('email', trim($this->email))))
            ->limit(5)->get(['id', 'name', 'phone', 'email', 'student_code', 'status']);
    }

    public function updated(string $field): void
    {
        if (in_array($field, ['name', 'email', 'phone'], true)) {
            $this->confirmDuplicate = false;
        }
        if ($field === 'pickShift') {
            $this->pickSeat = null; // availability is per shift
        }
    }

    public function mount(?string $student = null): void
    {
        if ($student === null) {
            $this->authorizePermission('students.create');
            $this->registered_on = today()->toDateString();

            return;
        }

        $this->authorizePermission('students.update');

        $model = Student::findOrFail($student); // tenant-scoped: another library's id is a 404
        $this->studentId = $model->id;
        $this->fill([
            'name' => $model->name, 'phone' => $model->phone, 'email' => (string) $model->email, 'address' => (string) $model->address,
            'emergency_contact_name' => (string) $model->emergency_contact_name, 'emergency_contact_phone' => (string) $model->emergency_contact_phone,
            'registered_on' => $model->registered_on->toDateString(), 'notes' => (string) $model->notes, 'status' => $model->status, 'member_type' => (string) $model->member_type, 'is_vip' => (bool) $model->is_vip, 'biometric_id' => (string) $model->biometric_id,
        ]);
        $this->existingPhoto = $model->photo_path;
    }

    public function save()
    {
        $this->authorizePermission($this->studentId ? 'students.update' : 'students.create');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            // The same phone twice in one library is treated as a duplicate registration.
            'phone' => ['required', 'string', 'max:30', Rule::unique('students', 'phone')
                ->where('library_id', \App\Support\Tenancy::currentId())->ignore($this->studentId)],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'registered_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'in:active,suspended,inactive'],
            'member_type' => ['required', 'in:'.implode(',', array_keys(\App\Support\CirculationRules::memberTypes()))],
            'is_vip' => ['boolean'],
            // the number enrolled on the fingerprint / face / card machine; one student per number in a library
            'biometric_id' => ['nullable', 'string', 'max:40', Rule::unique('students', 'biometric_id')->where('library_id', \App\Support\Tenancy::currentId())->ignore($this->studentId)],
            'photo' => ['nullable', 'image', 'max:2048'],
        ], ['phone.unique' => 'A student with this phone number is already registered.', 'biometric_id.unique' => 'Another student already has this biometric / card ID.']);

        if (! $this->studentId && $this->duplicates->isNotEmpty() && ! $this->confirmDuplicate) {
            $this->addError('name', 'This looks like someone already registered. Check the list below, then confirm it is a different person.');

            return;
        }

        if (! $this->studentId) {
            try {
                \App\Support\Entitlements::assertCanAdd('students');   // plan limit
            } catch (\App\Exceptions\BusinessRuleException $e) {
                $this->addError('name', $e->getMessage());

                return;
            }
        }

        $photo = $data['photo'] ?? null;
        unset($data['photo']);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        if ($this->studentId) {
            $student = Student::findOrFail($this->studentId);

            // Only people allowed to deactivate/suspend may change the status.
            if ($data['status'] !== $student->status && ! $this->can('students.deactivate')) {
                $data['status'] = $student->status;
            }
            $student->fill($data);
        } else {
            $data['status'] = Student::ACTIVE;
            $student = new Student($data);
        }

        if ($photo) {
            $student->photo_path = $photo->store('students', 'public');
        }
        $changed = array_keys($student->getDirty());
        $student->save();

        if ($this->studentId && $changed) {
            AuditLog::record('student.updated', $student, ['changed' => $changed]);
        }

        if ((! $this->studentId || ! $student->activeAllocation()->exists()) && $this->canSeatFor($student)) {
            session()->flash('notice', $this->studentId ? 'Student updated.' : "{$student->name} registered as {$student->student_code}.");
            $this->openSeats($student, true);

            return;
        }

        session()->flash('notice', $this->studentId ? 'Student updated.' : "{$student->name} registered as {$student->student_code}.");

        return $this->redirect(route('app.students.show', ['library' => \App\Support\Tenancy::slug(), 'student' => $student->id]), navigate: true);
    }

    protected function currentLibrarySlug(): string
    {
        return request()->attributes->get('library')?->slug ?? request()->route('library');
    }
}
?>

<div class="max-w-4xl space-y-5">
    <x-slot:header><x-ui.page-title :title="$studentId ? 'Edit student' : 'Register student'" :subtitle="$studentId ? $name : 'Add a new student to this library'" /></x-slot:header>

    <form wire:submit="save" class="space-y-5">
        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-4 text-sm font-semibold text-gray-900">Student details</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="Full name" required wire:model="name" />
                <x-ui.input name="phone" label="Phone" type="tel" required wire:model="phone" />
                <x-ui.input name="email" label="Email" type="email" wire:model="email" />
                <x-ui.input name="registered_on" label="Registration date" type="date" required wire:model="registered_on" />
                <x-ui.textarea name="address" label="Address" wire:model="address" class="sm:col-span-2" />
                <div>
                    <label class="block text-sm font-medium text-gray-700">Photograph</label>
                    <input type="file" wire:model="photo" accept="image/*" class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700">
                    @error('photo') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    @if ($photo && $photo->isPreviewable()) <img src="{{ $photo->temporaryUrl() }}" class="mt-2 h-16 w-16 rounded-full object-cover" alt=""> @elseif ($existingPhoto) <img src="{{ asset('storage/'.$existingPhoto) }}" class="mt-2 h-16 w-16 rounded-full object-cover" alt=""> @endif
                </div>
                @if (\App\Support\Entitlements::hasModule('attendance'))
                    <x-ui.input name="biometric_id" label="Biometric / card ID" wire:model="biometric_id" hint="The number the fingerprint, face or card machine uses for this student. Leave empty if not enrolled." />
                @endif
                <label class="flex items-center gap-2 self-end pb-2 text-sm font-medium text-gray-700"><input type="checkbox" wire:model="is_vip" class="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"> VIP member</label>
                @if (\App\Support\Entitlements::hasModule('books'))
                    <x-ui.select name="member_type" label="Member type" wire:model="member_type" hint="Decides the library's lending rules (loan period, limits, fines).">
                        @foreach (\App\Support\CirculationRules::memberTypes() as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach
                    </x-ui.select>
                @endif
                @if ($studentId)
                    <x-ui.select name="status" label="Status" wire:model="status" :disabled="! $this->can('students.deactivate')">
                        <option value="active">Active</option><option value="suspended">Blocked (suspended)</option><option value="inactive">Left (inactive)</option>
                    </x-ui.select>
                @endif
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-4 text-sm font-semibold text-gray-900">Emergency contact &amp; notes</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="emergency_contact_name" label="Contact name" wire:model="emergency_contact_name" />
                <x-ui.input name="emergency_contact_phone" label="Contact phone" type="tel" wire:model="emergency_contact_phone" />
                <x-ui.textarea name="notes" label="Notes" wire:model="notes" class="sm:col-span-2" />
            </div>
        </section>

        @if ($studentId && $this->editStudent && $this->canSeatFor($this->editStudent))
            @php $held = $this->editStudent->activeAllocation; @endphp
            <section class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="mb-2 text-sm font-medium text-gray-700">Seat No</p>
                <button type="button" wire:click="openSeatPicker" class="flex w-full items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50/40 px-4 py-3 text-left hover:bg-amber-50">
                    <span class="flex items-center gap-3"><x-lm.icon name="seat" class="h-5 w-5 text-brand-700" /><span class="text-sm font-bold text-gray-900">{{ $held ? 'Seat '.$held->seat?->code.($held->seat?->hall ? ' · '.$held->seat->hall->name : '') : 'Allot Seat' }}</span></span>
                    <span class="rounded-full bg-brand-100 px-3 py-1 text-xs font-bold text-brand-700">{{ $held ? 'Change seat' : 'Select seat' }}</span>
                </button>
            </section>
        @endif

        @if ($this->duplicates->isNotEmpty())
            <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-semibold">Possible duplicate registration</p>
                <ul class="mt-2 space-y-1">
                    @foreach ($this->duplicates as $d)
                        <li wire:key="dup-{{ $d->id }}"><a href="{{ route('app.students.show', ['library' => \App\Support\Tenancy::slug(), 'student' => $d->id]) }}" target="_blank" class="font-medium underline">{{ $d->name }}</a> — {{ $d->student_code }} · {{ $d->phone }}{{ $d->email ? ' · '.$d->email : '' }} ({{ $d->status }})</li>
                    @endforeach
                </ul>
                <label class="mt-3 flex items-center gap-2"><input type="checkbox" wire:model="confirmDuplicate" class="rounded border-amber-400 text-brand-600 focus:ring-brand-500"> This is a different person, register anyway</label>
            </section>
        @endif

        <div class="flex items-center gap-3">
            <x-ui.button type="submit" wire:target="save,photo">
                <span wire:loading.remove wire:target="save">{{ $studentId ? 'Save changes' : 'Register student' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-ui.button>
            <a href="{{ route('app.students.index', ['library' => \App\Support\Tenancy::slug()]) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-800">Cancel</a>
        </div>
    </form>
    @if ($showSeat && $this->seatStudent)
        <x-ui.modal title="Seat selection" close="skipSeat()" max="max-w-4xl" wire:key="seat-modal">
            <div class="space-y-4">
                @php $cur = $this->seatStudent->activeAllocation; @endphp
                <p class="text-sm text-gray-600">
                    <strong class="text-gray-900">{{ $this->seatStudent->name }}</strong>@if ($seatAfterSave && ! $studentId) is registered as {{ $this->seatStudent->student_code }}@endif.
                    @if ($cur) Now on seat <strong>{{ $cur->seat?->code }}</strong>. Pick another seat to move them, or remove the seat.
                    @elseif ($this->needsPlan) Pick a seat and the membership plan it goes with, or skip and do it later. A seat needs a running membership, so <strong>Add</strong> starts the plan on the date below. Payment is recorded separately.
                    @else Pick a seat for their running membership, or skip.
                    @endif
                </p>

                <div class="max-w-xs">
                    <x-ui.select name="pickShift" label="Shift" wire:model.live="pickShift">@foreach (\App\Models\SeatAllocation::SHIFTS as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach</x-ui.select>
                </div>

                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="grid flex-1 gap-3 sm:grid-cols-2 {{ $this->needsPlan ? '' : 'hidden' }}">
                        @if ($this->needsPlan)
                            <x-ui.select name="pickPlan" label="Membership plan" wire:model="pickPlan">
                                @foreach ($this->plans as $p) <option value="{{ $p->id }}">{{ $p->name }} · ₹{{ number_format((float) $p->price) }}</option> @endforeach
                            </x-ui.select>
                            <x-ui.input name="pickStart" label="Starts on" type="date" wire:model="pickStart" />
                        @endif
                    </div>
                    <div class="ml-auto flex items-end gap-2">
                        @if ($cur)<button type="button" wire:click="removeSeat" wire:confirm="Take seat {{ $cur->seat?->code }} away from {{ $this->seatStudent->name }}?" class="rounded-xl border border-red-300 bg-white px-6 py-2.5 text-sm font-extrabold uppercase tracking-wide text-red-600 hover:bg-red-50">Remove</button>@endif
                        <button type="button" wire:click="skipSeat" class="rounded-xl border border-gray-300 bg-white px-8 py-2.5 text-sm font-extrabold uppercase tracking-wide text-gray-700 hover:bg-gray-50">{{ $seatAfterSave ? 'Skip' : 'Cancel' }}</button>
                        <button type="button" wire:click="addSeat" wire:loading.attr="disabled" @disabled(! $pickSeat) class="rounded-xl bg-gradient-to-r from-teal-800 to-slate-900 px-8 py-2.5 text-sm font-extrabold uppercase tracking-wide text-white shadow hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40">{{ $cur ? 'Change' : 'Add' }}</button>
                    </div>
                </div>
                @error('pickSeat') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p> @enderror
                @error('pickPlan') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p> @enderror
                @error('pickStart') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p> @enderror

                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="$set('hallFilter', '')" class="rounded-lg border px-3 py-1.5 text-xs font-semibold {{ $hallFilter === '' ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600' }}">All floors</button>
                    @foreach ($this->halls as $h)
                        <button type="button" wire:click="$set('hallFilter', '{{ $h->id }}')" wire:key="hf-{{ $h->id }}" class="rounded-lg border px-3 py-1.5 text-xs font-semibold {{ (string) $hallFilter === (string) $h->id ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600' }}">{{ $h->name }}</button>
                    @endforeach
                </div>

                <div class="grid max-h-[26rem] grid-cols-3 gap-3 overflow-y-auto pr-1 sm:grid-cols-5 lg:grid-cols-7">
                    @forelse ($this->seats as $seat)
                        @php
                            $seatState = $seat->stateFor($pickShift);
                            $free = $seatState === \App\Models\Seat::AVAILABLE;
                            $picked = $pickSeat === $seat->id;
                            $holder = $seat->holderFor($pickShift)?->student;
                            $mine = $holder && $holder->id === $this->newStudentId;
                            $state = $free ? 'available' : ($seatState === \App\Models\Seat::OCCUPIED ? 'occupied' : ($seatState === \App\Models\Seat::RESERVED ? 'reserved' : 'blocked'));
                            $badge = ['available' => 'bg-emerald-500', 'occupied' => 'bg-red-500', 'reserved' => 'bg-amber-500', 'blocked' => 'bg-gray-500'][$state];
                            $foot = ['available' => 'bg-emerald-50 text-emerald-700', 'occupied' => 'bg-orange-50 text-orange-700', 'reserved' => 'bg-amber-50 text-amber-700', 'blocked' => 'bg-gray-100 text-gray-500'][$state];
                            $label = $free ? ($picked ? 'Selected' : 'Available') : ($state === 'occupied' ? ($mine ? 'Current' : ($holder?->name ?: 'Occupied')) : ($state === 'reserved' ? 'Reserved' : ucfirst($seatState)));
                        @endphp
                        <button type="button" @if ($free) wire:click="selectSeat({{ $seat->id }})" @else disabled @endif wire:key="seat-{{ $seat->id }}" title="{{ $seat->code }} · {{ $label }}"
                            class="relative flex flex-col items-center overflow-hidden rounded-xl border bg-white text-center {{ $picked ? 'border-blue-600 ring-2 ring-blue-200' : 'border-gray-200' }} {{ $free ? 'hover:border-brand-400' : 'cursor-not-allowed' }}">
                            <span class="absolute left-1.5 top-1.5 rounded-md px-1.5 py-0.5 text-[10px] font-bold text-white {{ $badge }}">{{ $seat->code }}</span>
                            @if ($picked)
                                <span class="absolute right-1.5 top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-blue-600 text-[11px] font-bold text-white">✓</span>
                            @elseif ($state === 'occupied' && $holder?->student_code)
                                <span class="absolute right-1.5 top-1.5 rounded bg-gray-600 px-1 py-0.5 text-[9px] font-semibold text-white">#{{ \Illuminate\Support\Str::afterLast($holder->student_code, '-') }}</span>
                            @endif
                            <span class="mt-6 flex h-14 items-center justify-center"><x-lm.seat-art :state="$state" /></span>
                            <span class="mt-1.5 w-full truncate px-1 py-1.5 text-[11px] font-bold {{ $foot }}">{{ $label }}</span>
                        </button>
                    @empty
                        <p class="col-span-full py-8 text-center text-sm text-gray-400">No seats in this hall.</p>
                    @endforelse
                </div>
            </div>
        </x-ui.modal>
    @endif
</div>
