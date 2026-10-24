<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Models\AuditLog;
use App\Models\Student;
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
    public $photo = null;
    public ?string $existingPhoto = null;

    public bool $confirmDuplicate = false;

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
            'registered_on' => $model->registered_on->toDateString(), 'notes' => (string) $model->notes, 'status' => $model->status, 'member_type' => (string) $model->member_type,
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
            'photo' => ['nullable', 'image', 'max:2048'],
        ], ['phone.unique' => 'A student with this phone number is already registered.']);

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
                @if (\App\Support\Entitlements::hasModule('books'))
                    <x-ui.select name="member_type" label="Member type" wire:model="member_type" hint="Decides the library's lending rules (loan period, limits, fines).">
                        @foreach (\App\Support\CirculationRules::memberTypes() as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach
                    </x-ui.select>
                @endif
                @if ($studentId)
                    <x-ui.select name="status" label="Status" wire:model="status" :disabled="! $this->can('students.deactivate')">
                        <option value="active">Active</option><option value="suspended">Suspended</option><option value="inactive">Inactive</option>
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
</div>
