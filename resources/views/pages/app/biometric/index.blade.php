<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Models\Library;
use App\Support\Entitlements;
use App\Support\Tenancy;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Fingerprint / face / card machines that mark attendance by themselves. Manual check-in on the Attendance page keeps working. */
new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $serial = '';
    public string $location = '';

    /** Shown once, right after it is made: only the hash is stored. */
    public string $newToken = '';
    public string $newTokenFor = '';

    #[Url]
    public string $result = '';

    public function mount(): void
    {
        $this->authorizePermission('attendance.view');
    }

    protected function ownDevice(int $id): BiometricDevice
    {
        return Library::findOrFail(Tenancy::currentId())->biometricDevices()->findOrFail($id);
    }

    #[Computed]
    public function devices()
    {
        return Library::findOrFail(Tenancy::currentId())->biometricDevices()
            ->withCount(['punches as today_count' => fn ($q) => $q->whereDate('punched_at', today())])
            ->orderBy('name')->get();
    }

    #[Computed]
    public function punches()
    {
        return BiometricPunch::with(['device:id,name', 'student:id,name,student_code'])
            ->when(isset(BiometricPunch::RESULTS[$this->result]), fn ($q) => $q->where('result', $this->result))
            ->latest('punched_at')->latest('id')->limit(40)->get();
    }

    #[Computed]
    public function unknownToday(): int
    {
        return BiometricPunch::where('result', 'unknown')->whereDate('punched_at', today())->count();
    }

    public function create(): void
    {
        $this->authorizePermission('attendance.correct');
        $this->reset(['editingId', 'name', 'serial', 'location']);
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorizePermission('attendance.correct');
        $d = $this->ownDevice($id);
        $this->editingId = $d->id;
        $this->fill(['name' => $d->name, 'serial' => $d->serial, 'location' => (string) $d->location]);
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizePermission('attendance.correct');
        Entitlements::assertWritable();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            // A machine is known by its serial number everywhere, so the same one cannot belong to two libraries.
            'serial' => ['required', 'string', 'max:80', Rule::unique('biometric_devices', 'serial')->ignore($this->editingId)],
            'location' => ['nullable', 'string', 'max:120'],
        ], ['serial.unique' => 'This serial number is already registered.'], ['serial' => 'serial number']);

        $library = Library::findOrFail(Tenancy::currentId());
        $payload = ['name' => $data['name'], 'serial' => trim($data['serial']), 'location' => $data['location'] ?: null];

        if ($this->editingId) {
            $this->ownDevice($this->editingId)->update($payload);
        } else {
            $device = $library->biometricDevices()->create($payload);
            $this->newToken = $device->issueToken();
            $this->newTokenFor = $device->name;
        }

        $this->showForm = false;
        unset($this->devices);
        $this->dispatch('notify', message: 'Device saved.');
    }

    public function toggle(int $id): void
    {
        $this->authorizePermission('attendance.correct');
        Entitlements::assertWritable();
        $d = $this->ownDevice($id);
        $d->update(['is_active' => ! $d->is_active]);
        unset($this->devices);
    }

    public function regenerateToken(int $id): void
    {
        $this->authorizePermission('attendance.correct');
        Entitlements::assertWritable();
        $d = $this->ownDevice($id);
        $this->newToken = $d->issueToken();
        $this->newTokenFor = $d->name;
    }

    public function remove(int $id): void
    {
        $this->authorizePermission('attendance.correct');
        Entitlements::assertWritable();
        $this->ownDevice($id)->delete();
        unset($this->devices, $this->punches);
        $this->dispatch('notify', message: 'Device removed.');
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function dismissToken(): void
    {
        $this->reset(['newToken', 'newTokenFor']);
    }
}
?>

<div class="space-y-6">
    <x-slot:header><x-ui.page-title title="Biometric devices" subtitle="Fingerprint, face or card machines that mark attendance by themselves" /></x-slot:header>

    @php $canManage = $this->can('attendance.correct'); $color = ['checked_in' => 'green', 'checked_out' => 'blue', 'ignored' => 'gray', 'rejected' => 'red', 'unknown' => 'amber']; @endphp

    @if ($newToken)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4" wire:key="token-box">
            <p class="text-sm font-semibold text-amber-900">Access token for {{ $newTokenFor }}</p>
            <p class="mt-1 text-xs text-amber-800">Copy it now. It is not shown again (make a new one if you lose it). ZKTeco-type machines do not need it; it is for card readers, kiosks or your own software that call the JSON address below.</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <code class="break-all rounded-lg bg-white px-3 py-2 text-sm font-semibold text-gray-900">{{ $newToken }}</code>
                <button type="button" x-data x-on:click="navigator.clipboard.writeText('{{ $newToken }}'); $el.textContent = 'Copied'" class="rounded-lg border border-amber-300 bg-white px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">Copy</button>
                <button type="button" wire:click="dismissToken" class="rounded-lg px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">I have saved it</button>
            </div>
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-900">Your devices</h3>
                <p class="text-xs text-gray-500">A student's first punch of the day checks them in and the next one checks them out. Checking in by hand on the Attendance page still works exactly as before.</p>
            </div>
            @if ($canManage) <x-ui.button wire:click="create">Add device</x-ui.button> @endif
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Device</th><th class="px-4 py-3 font-medium">Serial number</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3 font-medium">Last seen</th><th class="px-4 py-3 text-right font-medium">Punches today</th><th class="px-4 py-3 text-right font-medium">Actions</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->devices as $d)
                        <tr wire:key="dev-{{ $d->id }}">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $d->name }}@if ($d->location)<div class="text-xs font-normal text-gray-400">{{ $d->location }}</div>@endif</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $d->serial }}</td>
                            <td class="px-4 py-3">
                                @if (! $d->is_active) <x-ui.badge color="gray">Switched off</x-ui.badge>
                                @elseif ($d->isOnline()) <x-ui.badge color="green">Online</x-ui.badge>
                                @else <x-ui.badge color="amber">{{ $d->last_seen_at ? 'Not responding' : 'Waiting for first contact' }}</x-ui.badge> @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $d->last_seen_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-gray-700">{{ $d->today_count }}</td>
                            <td class="space-x-3 px-4 py-3 text-right">
                                @if ($canManage)
                                    <button wire:click="edit({{ $d->id }})" class="font-medium text-gray-500 hover:text-gray-800">Edit</button>
                                    <button wire:click="toggle({{ $d->id }})" class="font-medium text-amber-600 hover:text-amber-800">{{ $d->is_active ? 'Switch off' : 'Switch on' }}</button>
                                    <button wire:click="regenerateToken({{ $d->id }})" wire:confirm="Make a new access token? The old one stops working." class="font-medium text-brand-600 hover:text-brand-800">New token</button>
                                    <button wire:click="remove({{ $d->id }})" wire:confirm="Remove {{ $d->name }} and its punch history?" class="font-medium text-red-600 hover:text-red-800">Remove</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">No devices yet. Add your machine's serial number, then point the machine at this site (steps below).</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-gray-900">Connect a fingerprint / face machine (ZKTeco, eSSL, Realtime)</h3>
            <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-gray-600">
                <li>Add the machine here with the serial number printed on it (also under the machine's menu, Device Info).</li>
                <li>On the machine open <strong>Menu → Comm. → Cloud Server Setting</strong> (called ADMS on some models).</li>
                <li>Set <strong>Server Address</strong> to <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-900">{{ request()->getHost() }}</code> and <strong>Port</strong> to <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-900">{{ request()->getPort() }}</code>{{ request()->isSecure() ? ' (HTTPS on)' : '' }}.</li>
                <li>Enrol each student on the machine, then type the same <strong>ID the machine shows</strong> into the student's <em>Biometric / card ID</em> field.</li>
                <li>This page shows <em>Online</em> a few seconds after the machine connects.</li>
            </ol>
            <p class="mt-3 text-xs text-gray-500">Machines connect over the internet, so this site must be reachable from where the machine sits (a public address, not a local one).</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-gray-900">Other devices and software (JSON)</h3>
            <p class="mt-2 text-sm text-gray-600">Card readers, kiosks or your own program can post punches. Use the device's access token as a Bearer token.</p>
            <pre class="mt-3 overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs leading-relaxed text-gray-100">POST {{ route('biometric.punches') }}
Authorization: Bearer &lt;token&gt;
Content-Type: application/json

{"pin": "101", "time": "{{ now()->format('Y-m-d H:i:s') }}"}</pre>
            <p class="mt-2 text-xs text-gray-500">Leave out <code>time</code> to use the moment it arrives. Send up to 200 at once as <code>{"punches": [{"pin": "101"}, ...]}</code>.</p>
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-900">Recent punches</h3>
                @if ($this->unknownToday > 0) <p class="text-xs text-amber-700">{{ $this->unknownToday }} punch{{ $this->unknownToday === 1 ? '' : 'es' }} today from an ID no student has. Add that ID on the student's page.</p> @endif
            </div>
            <select wire:model.live="result" class="rounded-lg border-gray-300 text-sm"><option value="">All</option>@foreach (\App\Models\BiometricPunch::RESULTS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Time</th><th class="px-4 py-3 font-medium">Device</th><th class="px-4 py-3 font-medium">ID</th><th class="px-4 py-3 font-medium">Student</th><th class="px-4 py-3 font-medium">Result</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->punches as $p)
                        <tr wire:key="pu-{{ $p->id }}">
                            <td class="whitespace-nowrap px-4 py-3 text-gray-600">{{ $p->punched_at->format('d M, H:i:s') }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $p->device?->name }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $p->pin }}</td>
                            <td class="px-4 py-3 text-gray-900">{{ $p->student?->name ?? '—' }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$color[$p->result] ?? 'gray'">{{ \App\Models\BiometricPunch::RESULTS[$p->result] ?? $p->result }}</x-ui.badge>@if ($p->message && ! in_array($p->result, ['checked_in', 'checked_out'], true))<div class="mt-1 max-w-xs text-xs text-gray-500">{{ $p->message }}</div>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">No punches yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($showForm)
        <x-ui.modal :title="$editingId ? 'Edit device' : 'Add device'" max="max-w-lg" wire:key="dev-modal">
            <form wire:submit="save" class="space-y-4">
                <x-ui.input name="name" label="Device name" required wire:model="name" hint="For example: Main entrance" />
                <x-ui.input name="serial" label="Serial number" required wire:model="serial" hint="Printed on the machine, or under Menu → System Info → Device Info." />
                <x-ui.input name="location" label="Location" wire:model="location" />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeForm">Cancel</x-ui.button><x-ui.button type="submit">Save</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
