<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Models\MembershipPlan;
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
    public string $duration_unit = 'monthly';
    public string $price = '';
    public string $registration_fee = '0';
    public string $seat_type = 'flexible';
    public string $shift_start = '';
    public string $shift_end = '';
    public string $grace_days = '0';
    public string $status = 'active';

    public function mount(): void
    {
        $this->authorizePermission('memberships.view');
    }

    #[Computed]
    public function plans()
    {
        return MembershipPlan::orderBy('price')->get();
    }

    public function create(): void
    {
        $this->authorizePermission('plans.manage');
        $this->reset(['editingId', 'name', 'description', 'price', 'shift_start', 'shift_end']);
        $this->fill(['duration_unit' => 'monthly', 'registration_fee' => '0', 'seat_type' => 'flexible', 'grace_days' => '0', 'status' => 'active']);
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizePermission('plans.manage');
        $plan = MembershipPlan::findOrFail($id);

        $this->editingId = $plan->id;
        $this->fill([
            'name' => $plan->name, 'description' => (string) $plan->description, 'duration_unit' => $plan->duration_unit,
            'price' => (string) $plan->price, 'registration_fee' => (string) $plan->registration_fee, 'seat_type' => $plan->seat_type,
            'grace_days' => (string) $plan->grace_days, 'status' => $plan->status,
            'shift_start' => $plan->shift_start ? substr($plan->shift_start, 0, 5) : '',
            'shift_end' => $plan->shift_end ? substr($plan->shift_end, 0, 5) : '',
        ]);
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizePermission('plans.manage');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('membership_plans', 'name')->where('library_id', Tenancy::currentId())->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_unit' => ['required', Rule::in(array_keys(MembershipPlan::DURATIONS))],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'registration_fee' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'seat_type' => ['required', Rule::in(array_keys(MembershipPlan::SEAT_TYPES))],
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:90'],
            'status' => ['required', 'in:active,inactive'],
        ]);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        $this->editingId ? MembershipPlan::findOrFail($this->editingId)->update($data) : MembershipPlan::create($data);

        $this->showModal = false;
        unset($this->plans);
        $this->dispatch('notify', message: 'Plan saved. Existing memberships keep the price they were sold at.');
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Membership plans" subtitle="What students can buy at this library" /></x-slot:header>

    @if ($this->can('plans.manage'))
        <div class="flex justify-end"><x-ui.button wire:click="create"><x-lm.icon name="plus" class="h-4 w-4" /> Add plan</x-ui.button></div>
    @endif

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Plan</th><th class="px-4 py-3 font-medium">Duration</th><th class="px-4 py-3 text-right font-medium">Price</th><th class="px-4 py-3 text-right font-medium">Reg. fee</th><th class="px-4 py-3 font-medium">Seat</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($this->plans as $plan)
                    <tr wire:key="plan-{{ $plan->id }}">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $plan->name }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ \App\Models\MembershipPlan::DURATIONS[$plan->duration_unit] }}</td>
                        <td class="px-4 py-3 text-right">₹{{ number_format((float) $plan->price, 2) }}</td>
                        <td class="px-4 py-3 text-right text-gray-600">₹{{ number_format((float) $plan->registration_fee, 2) }}</td>
                        <td class="px-4 py-3"><x-ui.badge>{{ \App\Models\MembershipPlan::SEAT_TYPES[$plan->seat_type] }}</x-ui.badge></td>
                        <td class="px-4 py-3"><x-ui.badge :color="$plan->status === 'active' ? 'green' : 'gray'">{{ ucfirst($plan->status) }}</x-ui.badge></td>
                        <td class="px-4 py-3 text-right">@if ($this->can('plans.manage'))<button wire:click="edit({{ $plan->id }})" class="font-medium text-brand-600 hover:text-brand-800">Edit</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No plans yet. Create a plan before assigning memberships.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showModal)
        <x-ui.modal :title="$editingId ? 'Edit plan' : 'Add plan'" max="max-w-2xl" wire:key="plan-modal">
            <form wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="name" label="Plan name" required wire:model="name" />
                    <x-ui.select name="duration_unit" label="Duration" wire:model="duration_unit">
                        @foreach (\App\Models\MembershipPlan::DURATIONS as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach
                    </x-ui.select>
                    <x-ui.input name="price" label="Price (₹)" type="number" step="0.01" min="0" required wire:model="price" />
                    <x-ui.input name="registration_fee" label="Registration fee (₹)" type="number" step="0.01" min="0" wire:model="registration_fee" hint="Charged once, on a student's first membership." />
                    <x-ui.select name="seat_type" label="Seat type" wire:model="seat_type">
                        @foreach (\App\Models\MembershipPlan::SEAT_TYPES as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach
                    </x-ui.select>
                    <x-ui.input name="grace_days" label="Grace days" type="number" min="0" wire:model="grace_days" hint="Days after expiry before the membership counts as lapsed." />
                    <x-ui.input name="shift_start" label="Shift start" type="time" wire:model="shift_start" />
                    <x-ui.input name="shift_end" label="Shift end" type="time" wire:model="shift_end" />
                    <x-ui.select name="status" label="Status" wire:model="status"><option value="active">Active</option><option value="inactive">Inactive</option></x-ui.select>
                </div>
                <x-ui.textarea name="description" label="Description" wire:model="description" />
                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button>
                    <x-ui.button type="submit">Save</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
