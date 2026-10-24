<?php

use App\Models\AuditLog;
use App\Models\Library;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    public int $libraryId;
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $address = '';
    public string $timezone = 'Asia/Kolkata';
    public string $currency = 'INR';
    public string $opening_time = '';
    public string $closing_time = '';

    public function mount(string $library): void
    {
        $lib = Library::findOrFail($library);
        $this->libraryId = $lib->id;
        $this->fill([
            'name' => $lib->name,
            'email' => (string) $lib->email,
            'phone' => (string) $lib->phone,
            'address' => (string) $lib->address,
            'timezone' => $lib->timezone,
            'currency' => $lib->currency,
            'opening_time' => $lib->opening_time ? substr($lib->opening_time, 0, 5) : '',
            'closing_time' => $lib->closing_time ? substr($lib->closing_time, 0, 5) : '',
        ]);
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'max:8'],
            'opening_time' => ['nullable', 'date_format:H:i'],
            'closing_time' => ['nullable', 'date_format:H:i', 'after:opening_time'],
        ]);

        $library = Library::findOrFail($this->libraryId);
        $library->fill(array_map(fn ($v) => $v === '' ? null : $v, $data));
        $changed = array_keys($library->getDirty());
        $library->save();

        // Status is deliberately not editable here: it changes only through the audited actions.
        if ($changed) {
            AuditLog::record('library.updated', $library, ['changed' => $changed], $library->id);
        }

        session()->flash('notice', 'Library details saved.');
        $this->redirect(route('admin.libraries.show', $library), navigate: true);
    }
}
?>

<div class="max-w-4xl space-y-5">
    <x-slot:header><x-ui.page-title title="Edit library" :subtitle="$name" /></x-slot:header>

    <form wire:submit="save" class="space-y-5">
        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="Library name" required wire:model="name" />
                <x-ui.input name="email" label="Library email" type="email" wire:model="email" />
                <x-ui.input name="phone" label="Library phone" type="tel" wire:model="phone" />
                <x-ui.select name="timezone" label="Time zone" wire:model="timezone">
                    @foreach (timezone_identifiers_list() as $tz) <option value="{{ $tz }}">{{ $tz }}</option> @endforeach
                </x-ui.select>
                <x-ui.input name="opening_time" label="Opening time" type="time" wire:model="opening_time" />
                <x-ui.input name="closing_time" label="Closing time" type="time" wire:model="closing_time" />
                <x-ui.input name="currency" label="Currency" wire:model="currency" />
                <x-ui.textarea name="address" label="Address" wire:model="address" class="sm:col-span-2" />
            </div>
        </section>

        <div class="flex items-center gap-3">
            <x-ui.button type="submit" wire:target="save">Save changes</x-ui.button>
            <a href="{{ route('admin.libraries.show', $libraryId) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-800">Cancel</a>
        </div>
    </form>
</div>
