<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Models\StudyHall;
use App\Support\Tenancy;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions;

    public bool $showModal = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $description = '';
    public string $capacity = '';
    public string $supervisor_id = '';
    public string $opening_time = '';
    public string $closing_time = '';
    public string $status = 'active';

    public function mount(): void
    {
        $this->authorizePermission('seats.view');
    }

    #[Computed]
    public function halls()
    {
        return StudyHall::with('supervisor')->withCount('seats')->orderBy('name')->get();
    }

    #[Computed]
    public function staff()
    {
        return \App\Models\Library::find(Tenancy::currentId())->users()->wherePivot('is_active', true)->orderBy('users.name')->get(['users.id', 'users.name']);
    }

    public function create(): void
    {
        $this->authorizePermission('halls.manage');
        $this->reset(['editingId', 'name', 'description', 'capacity', 'supervisor_id', 'opening_time', 'closing_time']);
        $this->status = 'active';
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizePermission('halls.manage');
        $hall = StudyHall::findOrFail($id);

        $this->editingId = $hall->id;
        $this->fill([
            'name' => $hall->name, 'description' => (string) $hall->description, 'capacity' => (string) $hall->capacity,
            'supervisor_id' => (string) $hall->supervisor_id, 'status' => $hall->status,
            'opening_time' => $hall->opening_time ? substr($hall->opening_time, 0, 5) : '',
            'closing_time' => $hall->closing_time ? substr($hall->closing_time, 0, 5) : '',
        ]);
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizePermission('halls.manage');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('study_halls', 'name')->where('library_id', Tenancy::currentId())->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:10000'],
            // The supervisor must be someone who actually belongs to this library.
            'supervisor_id' => ['nullable', Rule::in($this->staff->pluck('id')->map(fn ($i) => (string) $i)->all())],
            'opening_time' => ['nullable', 'date_format:H:i'],
            'closing_time' => ['nullable', 'date_format:H:i', 'after:opening_time'],
            'status' => ['required', 'in:active,inactive'],
        ]);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        $this->editingId ? StudyHall::findOrFail($this->editingId)->update($data) : StudyHall::create($data);

        $this->showModal = false;
        unset($this->halls);
        $this->dispatch('notify', message: 'Hall saved.');
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Halls &amp; Floors" subtitle="Rooms and floors that hold your seats" /></x-slot:header>

    @if ($this->can('halls.manage'))
        <div class="flex justify-end"><x-ui.button wire:click="create"><x-lm.icon name="plus" class="h-4 w-4" /> Add hall</x-ui.button></div>
    @endif

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Name</th><th class="px-4 py-3 text-right font-medium">Seats</th><th class="px-4 py-3 text-right font-medium">Capacity</th><th class="px-4 py-3 font-medium">Supervisor</th><th class="px-4 py-3 font-medium">Hours</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($this->halls as $hall)
                    <tr wire:key="hall-{{ $hall->id }}">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $hall->name }}</td>
                        <td class="px-4 py-3 text-right">{{ $hall->seats_count }}</td>
                        <td class="px-4 py-3 text-right text-gray-600">{{ $hall->capacity ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $hall->supervisor?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $hall->opening_time ? substr($hall->opening_time, 0, 5).' – '.substr($hall->closing_time ?? '', 0, 5) : '—' }}</td>
                        <td class="px-4 py-3"><x-ui.badge :color="$hall->status === 'active' ? 'green' : 'gray'">{{ ucfirst($hall->status) }}</x-ui.badge></td>
                        <td class="px-4 py-3 text-right">@if ($this->can('halls.manage'))<button wire:click="edit({{ $hall->id }})" class="font-medium text-brand-600 hover:text-brand-800">Edit</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No halls yet. Add your first hall or floor, then generate its seats.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showModal)
        <x-ui.modal :title="$editingId ? 'Edit hall' : 'Add hall'" max="max-w-xl" wire:key="hall-modal">
            <form wire:submit="save" class="space-y-4">
                <x-ui.input name="name" label="Name" required wire:model="name" />
                <x-ui.textarea name="description" label="Description" wire:model="description" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="capacity" label="Capacity" type="number" min="0" wire:model="capacity" />
                    <x-ui.select name="status" label="Status" wire:model="status"><option value="active">Active</option><option value="inactive">Inactive</option></x-ui.select>
                    <x-ui.select name="supervisor_id" label="Floor supervisor" wire:model="supervisor_id">
                        <option value="">None</option>
                        @foreach ($this->staff as $u) <option value="{{ $u->id }}">{{ $u->name }}</option> @endforeach
                    </x-ui.select>
                    <div></div>
                    <x-ui.input name="opening_time" label="Opening time" type="time" wire:model="opening_time" />
                    <x-ui.input name="closing_time" label="Closing time" type="time" wire:model="closing_time" />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button>
                    <x-ui.button type="submit">Save</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
