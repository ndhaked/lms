<?php

use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\BillingSettings;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    public bool $showModal = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $description = '';
    public string $price_monthly = '';
    public string $price_quarterly = '';
    public string $price_annual = '';
    public string $trial_days = '0';
    public string $max_students = '';
    public string $max_seats = '';
    public string $max_staff = '';
    public array $modules = [];
    public string $grace_days = '7';
    public string $status = 'active';

    public ?int $viewingId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('plans.manage'), 403);
    }

    protected function authorizeManage(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('plans.manage'), 403);
    }

    #[Computed]
    public function plans()
    {
        return SubscriptionPlan::query()
            ->withCount(['subscriptions as libraries_count' => fn ($q) => $q->whereIn('status', ['trial', 'active', 'pending', 'expired', 'suspended'])])
            ->orderByRaw("status = 'active' desc")->orderBy('price_monthly')->get();
    }

    #[Computed]
    public function using()
    {
        return $this->viewingId
            ? Subscription::with('library')->where('subscription_plan_id', $this->viewingId)->where('status', '!=', 'cancelled')->latest('id')->limit(50)->get()
            : collect();
    }

    public function create(): void
    {
        $this->authorizeManage();
        $this->reset(['editingId', 'name', 'description', 'price_monthly', 'price_quarterly', 'price_annual', 'max_students', 'max_seats', 'max_staff']);
        $this->fill(['trial_days' => (string) BillingSettings::get('default_trial_days'), 'grace_days' => (string) BillingSettings::get('default_grace_days'),
            'status' => 'active', 'modules' => array_keys(SubscriptionPlan::MODULES)]);
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $p = SubscriptionPlan::findOrFail($id);

        $this->editingId = $p->id;
        $this->fill([
            'name' => $p->name, 'description' => (string) $p->description,
            'price_monthly' => (string) ($p->price_monthly ?? ''), 'price_quarterly' => (string) ($p->price_quarterly ?? ''), 'price_annual' => (string) ($p->price_annual ?? ''),
            'trial_days' => (string) $p->trial_days, 'max_students' => (string) ($p->max_students ?? ''), 'max_seats' => (string) ($p->max_seats ?? ''),
            'max_staff' => (string) ($p->max_staff ?? ''), 'modules' => $p->modules ?? [], 'grace_days' => (string) $p->grace_days, 'status' => $p->status,
        ]);
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizeManage();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('subscription_plans', 'name')->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_monthly' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'price_quarterly' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'price_annual' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'trial_days' => ['required', 'integer', 'between:0,365'],
            'max_students' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_seats' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_staff' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'modules' => ['array'],
            'modules.*' => [Rule::in(array_keys(SubscriptionPlan::MODULES))],
            'grace_days' => ['required', 'integer', 'between:0,90'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        if ($data['price_monthly'] === '' && $data['price_quarterly'] === '' && $data['price_annual'] === '') {
            $this->addError('price_monthly', 'Set a price for at least one billing cycle.');

            return;
        }

        $payload = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $payload['modules'] = array_values($data['modules']);

        $plan = $this->editingId ? SubscriptionPlan::findOrFail($this->editingId) : new SubscriptionPlan;
        $plan->fill($payload);
        $changed = array_keys($plan->getDirty());
        $isNew = ! $plan->exists;
        $plan->save();

        AuditLog::record($isNew ? 'plan.created' : 'plan.updated', $plan, $isNew ? ['name' => $plan->name] : ['changed' => $changed]);

        $this->showModal = false;
        unset($this->plans);
        $this->dispatch('notify', message: $isNew ? 'Plan created.' : 'Plan saved. Existing subscriptions keep their terms until they renew.');
    }

    public function toggle(int $id): void
    {
        $this->authorizeManage();
        $p = SubscriptionPlan::findOrFail($id);
        $p->update(['status' => $p->status === 'active' ? 'inactive' : 'active']);
        AuditLog::record('plan.'.($p->status === 'active' ? 'activated' : 'deactivated'), $p, ['name' => $p->name]);
        unset($this->plans);
    }

    public function duplicate(int $id): void
    {
        $this->authorizeManage();
        $p = SubscriptionPlan::findOrFail($id);

        $copy = $p->replicate();
        $copy->name = $this->uniqueName($p->name.' (copy)');
        $copy->status = 'inactive';
        $copy->save();

        AuditLog::record('plan.duplicated', $copy, ['from' => $p->name]);
        unset($this->plans);
        $this->dispatch('notify', message: "Created “{$copy->name}” (inactive). Edit and activate it when ready.");
    }

    protected function uniqueName(string $base): string
    {
        $name = $base;
        for ($i = 2; SubscriptionPlan::where('name', $name)->exists(); $i++) {
            $name = "{$base} {$i}";
        }

        return $name;
    }

    public function showLibraries(int $id): void
    {
        $this->authorizeManage();
        SubscriptionPlan::findOrFail($id);
        $this->viewingId = $id;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->viewingId = null;
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Subscription plans" subtitle="What libraries pay for" /></x-slot:header>

    <div class="flex justify-end"><x-ui.button wire:click="create"><x-lm.icon name="plus" class="h-4 w-4" /> Add plan</x-ui.button></div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Plan</th><th class="px-4 py-3 text-right font-medium">Monthly</th><th class="px-4 py-3 text-right font-medium">Quarterly</th><th class="px-4 py-3 text-right font-medium">Annual</th><th class="px-4 py-3 font-medium">Limits</th><th class="px-4 py-3 font-medium">Trial / grace</th><th class="px-4 py-3 text-right font-medium">Libraries</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->plans as $p)
                        <tr wire:key="plan-{{ $p->id }}" class="{{ $p->status === 'active' ? '' : 'bg-gray-50/70 text-gray-400' }}">
                            <td class="px-4 py-3"><div class="font-medium {{ $p->status === 'active' ? 'text-gray-900' : '' }}">{{ $p->name }}</div><div class="text-xs text-gray-400">{{ \Illuminate\Support\Str::limit($p->description, 60) }}</div></td>
                            @foreach (['price_monthly', 'price_quarterly', 'price_annual'] as $col)
                                <td class="px-4 py-3 text-right">{{ $p->$col !== null ? '₹'.number_format((float) $p->$col, 2) : '—' }}</td>
                            @endforeach
                            <td class="px-4 py-3 text-xs text-gray-600">{{ $p->max_students ?? '∞' }} students<br>{{ $p->max_seats ?? '∞' }} seats · {{ $p->max_staff ?? '∞' }} staff</td>
                            <td class="px-4 py-3 text-xs text-gray-600">{{ $p->trial_days }}-day trial<br>{{ $p->grace_days }}-day grace</td>
                            <td class="px-4 py-3 text-right"><button wire:click="showLibraries({{ $p->id }})" class="font-medium text-brand-600 hover:text-brand-800">{{ $p->libraries_count }}</button></td>
                            <td class="px-4 py-3"><x-ui.badge :color="$p->status === 'active' ? 'green' : 'gray'">{{ ucfirst($p->status) }}</x-ui.badge></td>
                            <td class="space-x-3 px-4 py-3 text-right">
                                <button wire:click="edit({{ $p->id }})" class="font-medium text-brand-600 hover:text-brand-800">Edit</button>
                                <button wire:click="duplicate({{ $p->id }})" class="font-medium text-gray-500 hover:text-gray-800">Duplicate</button>
                                <button wire:click="toggle({{ $p->id }})" class="font-medium {{ $p->status === 'active' ? 'text-red-600 hover:text-red-800' : 'text-brand-600 hover:text-brand-800' }}">{{ $p->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">No plans yet. Create the first plan to start billing libraries.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($showModal)
        <x-ui.modal :title="$editingId ? 'Edit plan' : 'Add plan'" max="max-w-3xl" wire:key="plan-modal">
            <form wire:submit="save" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="name" label="Plan name" required wire:model="name" />
                    <x-ui.select name="status" label="Status" wire:model="status"><option value="active">Active</option><option value="inactive">Inactive</option></x-ui.select>
                    <x-ui.textarea name="description" label="Description" wire:model="description" class="sm:col-span-2" />
                </div>

                <div>
                    <p class="mb-2 text-sm font-medium text-gray-700">Prices (leave a cycle empty if it is not sold)</p>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.input name="price_monthly" label="Monthly (₹)" type="number" step="0.01" min="0" wire:model="price_monthly" />
                        <x-ui.input name="price_quarterly" label="Quarterly (₹)" type="number" step="0.01" min="0" wire:model="price_quarterly" />
                        <x-ui.input name="price_annual" label="Annual (₹)" type="number" step="0.01" min="0" wire:model="price_annual" />
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-sm font-medium text-gray-700">Limits (empty = unlimited)</p>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.input name="max_students" label="Max students" type="number" min="1" wire:model="max_students" />
                        <x-ui.input name="max_seats" label="Max seats" type="number" min="1" wire:model="max_seats" />
                        <x-ui.input name="max_staff" label="Max staff accounts" type="number" min="1" wire:model="max_staff" />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="trial_days" label="Trial (days)" type="number" min="0" wire:model="trial_days" />
                    <x-ui.input name="grace_days" label="Grace period (days)" type="number" min="0" wire:model="grace_days" hint="Full access continues this long after expiry." />
                </div>

                <div>
                    <p class="mb-2 text-sm font-medium text-gray-700">Enabled modules</p>
                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-400">Students, seats, memberships, payments (always on)</span>
                        @foreach (\App\Models\SubscriptionPlan::MODULES as $key => $label)
                            <label wire:key="mod-{{ $key }}" class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700"><input type="checkbox" wire:model="modules" value="{{ $key }}" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> {{ $label }}</label>
                        @endforeach
                    </div>
                </div>

                <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">Changes to price, limits and modules apply to existing libraries when they next renew (or when you change their plan manually). Existing subscriptions are never altered silently.</p>

                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Save plan</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    @if ($viewingId)
        <x-ui.modal title="Libraries on this plan" max="max-w-2xl" wire:key="use-modal">
            <ul class="divide-y divide-gray-100 text-sm">
                @forelse ($this->using as $s)
                    <li class="flex items-center justify-between py-2" wire:key="u-{{ $s->id }}">
                        <a href="{{ route('admin.subscriptions.show', $s) }}" wire:navigate class="font-medium text-gray-900 hover:text-brand-700">{{ $s->library->name }} <span class="text-xs text-gray-400">{{ $s->library->code }}</span></a>
                        <x-ui.badge>{{ \App\Models\Subscription::STATUSES[$s->status] }}</x-ui.badge>
                    </li>
                @empty
                    <li class="py-6 text-center text-gray-400">No library uses this plan.</li>
                @endforelse
            </ul>
        </x-ui.modal>
    @endif
</div>
