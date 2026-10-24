<?php

use App\Actions\ChangeLibraryStatus;
use App\Actions\SendAccountSetup;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\LibraryNote;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    public int $libraryId;

    #[Url]
    public string $tab = 'overview';

    public ?string $statusTarget = null;
    public string $reason = '';
    public bool $showNote = false;
    public string $noteBody = '';

    public function mount(string $library): void
    {
        $lib = Library::findOrFail($library);
        $this->libraryId = $lib->id;
        $this->expiryPolicy = (string) ($lib->expiry_policy ?? '');
    }

    #[Computed]
    public function library(): Library
    {
        return Library::with(['owner', 'users', 'notes.author', 'auditLogs.actor'])->findOrFail($this->libraryId);
    }

    // ---- subscription ----
    public string $expiryPolicy = '';      // '' = follow the platform default
    public bool $showAssign = false;
    public string $assignPlan = '';
    public string $assignCycle = 'monthly';
    public bool $assignTrial = false;

    public bool $showFree = false;
    public string $freePlan = '';
    public string $freeDays = '30';
    public string $freeCustom = '';
    public string $freeReason = '';

    #[Computed]
    public function subscription(): ?\App\Models\Subscription
    {
        return \App\Models\Subscription::currentFor($this->libraryId);
    }

    #[Computed]
    public function subscriptionPayments()
    {
        return \App\Models\SubscriptionPayment::where('library_id', $this->libraryId)->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function plans()
    {
        return \App\Models\SubscriptionPlan::where('status', 'active')->orderBy('price_monthly')->get();
    }

    public function assignPlanNow(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('subscriptions.manage'), 403);
        $this->validate(['assignPlan' => ['required', 'integer']], ['assignPlan.required' => 'Choose a plan.']);

        try {
            app(\App\Actions\Billing\CreateSubscription::class)(Library::findOrFail($this->libraryId), \App\Models\SubscriptionPlan::findOrFail($this->assignPlan), $this->assignCycle, $this->assignTrial);
        } catch (BusinessRuleException $e) {
            $this->addError('assignPlan', $e->getMessage());

            return;
        }

        $this->showAssign = false;
        unset($this->subscription, $this->library);
        $this->dispatch('notify', message: 'Plan assigned.');
    }

    public function savePolicy(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('subscriptions.manage'), 403);
        $this->validate(['expiryPolicy' => ['in:,billing_only,read_only']]);

        $library = Library::findOrFail($this->libraryId);
        $before = $library->expiry_policy;
        $library->forceFill(['expiry_policy' => $this->expiryPolicy === '' ? null : $this->expiryPolicy])->save();
        \App\Support\Entitlements::flush();

        AuditLog::record('library.expiry_policy_changed', $library, ['from' => $before, 'to' => $library->expiry_policy], $library->id);
        unset($this->library);
        $this->dispatch('notify', message: 'Access rule saved.');
    }

    // ---- modules (what this library may use beyond its plan) ----
    public bool $showModule = false;
    public string $modName = '';
    public string $modAction = 'grant';      // grant | revoke
    public string $modType = 'addon';
    public string $modMonthly = '';
    public string $modQuarterly = '';
    public string $modAnnual = '';
    public string $modExpires = '';
    public string $modReason = '';

    #[Computed]
    public function moduleRows(): array
    {
        $grants = \App\Models\LibraryModule::where('library_id', $this->libraryId)->get()->keyBy('module');

        return collect(\App\Models\SubscriptionPlan::MODULES)->map(fn ($label, $key) => [
            'key' => $key, 'label' => $label,
            'source' => \App\Support\Entitlements::moduleSource($key, $this->libraryId),
            'grant' => ($g = $grants[$key] ?? null) && $g->status === 'active' ? $g : null,
        ])->values()->all();
    }

    public function openModule(string $module, string $action): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('subscriptions.manage'), 403);
        abort_unless(array_key_exists($module, \App\Models\SubscriptionPlan::MODULES) && in_array($action, ['grant', 'revoke'], true), 422);

        $this->reset('modType', 'modExpires', 'modReason');
        $prices = \App\Actions\Billing\ManageLibraryModule::defaultPrices($module);
        [$this->modMonthly, $this->modQuarterly, $this->modAnnual] = [(string) ($prices['monthly'] ?? ''), (string) ($prices['quarterly'] ?? ''), (string) ($prices['annual'] ?? '')];
        $this->modName = $module;
        $this->modAction = $action;
        $this->resetErrorBag();
        $this->showModule = true;
    }

    public function saveModule(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('subscriptions.manage'), 403);
        $this->validate([
            'modReason' => ['required', 'string', 'max:500'],
            'modType' => ['in:addon,complimentary'],
            'modMonthly' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'modQuarterly' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'modAnnual' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'modExpires' => ['nullable', 'date'],
        ], [], ['modReason' => 'reason', 'modMonthly' => 'monthly price']);

        $library = Library::findOrFail($this->libraryId);
        $action = app(\App\Actions\Billing\ManageLibraryModule::class);

        try {
            if ($this->modAction === 'revoke') {
                $action->revoke($library, $this->modName, $this->modReason);
            } else {
                $action->grant($library, $this->modName, $this->modType, $this->modReason,
                    ['monthly' => $this->modMonthly, 'quarterly' => $this->modQuarterly, 'annual' => $this->modAnnual],
                    $this->modType === 'complimentary' && $this->modExpires !== '' ? \Illuminate\Support\Carbon::parse($this->modExpires) : null);
            }
        } catch (BusinessRuleException $e) {
            $this->addError('modReason', $e->getMessage());

            return;
        }

        $this->showModule = false;
        unset($this->moduleRows);
        $this->dispatch('notify', message: $this->modAction === 'revoke' ? 'Module disabled.' : 'Module enabled.');
    }

    public function openFree(): void    {
        abort_unless(auth()->user()->hasPlatformPermission('subscriptions.manage'), 403);
        $this->reset('freeDays', 'freeCustom', 'freeReason');
        $this->freePlan = (string) ($this->subscription?->subscription_plan_id ?? $this->plans->first()?->id ?? '');
        $this->resetErrorBag();
        $this->showFree = true;
    }

    public function grantFree(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('subscriptions.manage'), 403);
        $this->validate([
            'freePlan' => ['required', 'integer'],
            'freeDays' => ['required', 'in:15,30,60,custom'],
            'freeCustom' => ['required_if:freeDays,custom', 'nullable', 'integer', 'min:1', 'max:730'],
            'freeReason' => ['required', 'string', 'max:500'],
        ], ['freePlan.required' => 'Choose the plan whose limits apply.', 'freeCustom.required_if' => 'Enter the number of days.']);

        $days = $this->freeDays === 'custom' ? (int) $this->freeCustom : (int) $this->freeDays;

        try {
            app(\App\Actions\Billing\GrantFreePeriod::class)(Library::findOrFail($this->libraryId), \App\Models\SubscriptionPlan::findOrFail($this->freePlan), $days, $this->freeReason);
        } catch (BusinessRuleException $e) {
            $this->addError('freeReason', $e->getMessage());

            return;
        }

        $this->showFree = false;
        unset($this->subscription, $this->library);
        $this->dispatch('notify', message: "Free access granted for {$days} days.");
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    /** Targets the current status allows (see ChangeLibraryStatus::TRANSITIONS). */
    #[Computed]
    public function allowed(): array
    {
        return ChangeLibraryStatus::TRANSITIONS[$this->library->status] ?? [];
    }

    public function startStatus(string $to): void
    {
        abort_unless(in_array($to, $this->allowed, true), 422);
        $this->reset('reason');
        $this->resetErrorBag();
        $this->statusTarget = $to;
    }

    public function cancelStatus(): void
    {
        $this->statusTarget = null;
    }

    public function activateNow(): void
    {
        $this->applyStatus(Library::ACTIVE, null);
    }

    public function confirmStatus(): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->applyStatus($this->statusTarget, $this->reason);
    }

    protected function applyStatus(string $to, ?string $reason): void
    {
        try {
            app(ChangeLibraryStatus::class)(Library::findOrFail($this->libraryId), $to, $reason);
        } catch (BusinessRuleException $e) {
            $this->addError('reason', $e->getMessage());
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        $this->statusTarget = null;
        unset($this->library, $this->allowed);
        $this->dispatch('notify', message: 'Status updated.');
    }

    public function resend(): void
    {
        $owner = $this->library->owner;
        abort_unless($owner, 404);

        app(SendAccountSetup::class)($owner);
        AuditLog::record('library.setup_email_resent', $this->library, ['to' => $owner->email], $this->libraryId);
        unset($this->library);
        $this->dispatch('notify', message: "Setup email sent to {$owner->email}.");
    }

    public function saveNote(): void
    {
        $this->validate(['noteBody' => ['required', 'string', 'max:5000']]);

        LibraryNote::create(['library_id' => $this->libraryId, 'user_id' => auth()->id(), 'body' => $this->noteBody]);

        $this->reset(['showNote', 'noteBody']);
        unset($this->library);
        $this->tab = 'notes';
        $this->dispatch('notify', message: 'Note added.');
    }
}
?>

<div class="space-y-5">
    <x-slot:header>
        <x-ui.page-title :title="$this->library->name" :subtitle="$this->library->code.' · registered '.$this->library->created_at->format('d M Y')" />
    </x-slot:header>

    @php
        $lib = $this->library;
        $statusColor = match ($lib->status) { 'active' => 'green', 'pending' => 'blue', 'suspended' => 'amber', default => 'gray' };
        $tabs = ['overview' => 'Overview', 'owner' => 'Owner and staff', 'subscription' => 'Subscription', 'modules' => 'Modules', 'payments' => 'Payments', 'usage' => 'Usage and limits', 'activity' => 'Activity history', 'notes' => 'Support notes'];
        $labels = [Library::ACTIVE => 'Reactivate', Library::SUSPENDED => 'Suspend', Library::INACTIVE => 'Deactivate'];
    @endphp

    @if (session('notice'))
        <div class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-700">{{ session('notice') }}</div>
    @endif

    {{-- actions --}}
    <div class="flex flex-wrap items-center gap-2">
        <x-ui.badge :color="$statusColor" class="mr-2 px-3 py-1 text-sm">{{ \App\Models\Library::STATUSES[$lib->status] }}</x-ui.badge>

        <a href="{{ route('admin.libraries.edit', $lib) }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            <x-lm.icon name="pencil" class="h-4 w-4" /> Edit
        </a>

        @foreach ($this->allowed as $to)
            @if ($to === \App\Models\Library::ACTIVE && $lib->status === \App\Models\Library::PENDING)
                <x-ui.button wire:click="activateNow" wire:confirm="Activate {{ $lib->name }}? Its owner and staff will be able to sign in.">Activate</x-ui.button>
            @else
                <x-ui.button :variant="$to === 'suspended' ? 'warning' : ($to === 'inactive' ? 'danger' : 'primary')" wire:click="startStatus('{{ $to }}')">
                    {{ $labels[$to] ?? ucfirst($to) }}
                </x-ui.button>
            @endif
        @endforeach

        @if ($lib->owner)
            <x-ui.button variant="secondary" wire:click="resend" wire:confirm="Send a new password-setup link to {{ $lib->owner->email }}?">
                <x-lm.icon name="mail" class="h-4 w-4" /> Resend setup email
            </x-ui.button>
        @endif
        <x-ui.button variant="secondary" wire:click="$set('showNote', true)"><x-lm.icon name="note" class="h-4 w-4" /> Add note</x-ui.button>
    </div>

    {{-- tabs --}}
    <div class="rounded-xl border border-gray-200 bg-white">
        <div class="flex gap-1 overflow-x-auto border-b border-gray-200 px-3 pt-2">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" wire:key="tab-{{ $key }}"
                    class="whitespace-nowrap rounded-t-lg border-b-2 px-3 py-2.5 text-sm font-medium {{ $tab === $key ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="p-5">
            @if ($tab === 'overview')
                <dl class="grid gap-x-6 gap-y-5 text-sm sm:grid-cols-3">
                    @foreach ([
                        'Library' => $lib->name, 'Library code' => $lib->code, 'Email' => $lib->email, 'Phone' => $lib->phone,
                        'Time zone' => $lib->timezone, 'Currency' => $lib->currency, 'Address' => $lib->address,
                        'Registered' => $lib->created_at->format('d M Y, H:i'), 'Activated' => $lib->activated_at?->format('d M Y, H:i') ?? 'Not yet',
                    ] as $label => $value)
                        <div><dt class="text-xs font-medium text-gray-500">{{ $label }}</dt><dd class="mt-1 text-gray-900">{{ $value ?: '—' }}</dd></div>
                    @endforeach
                </dl>

            @elseif ($tab === 'owner')
                <h4 class="mb-3 text-sm font-semibold text-gray-900">Owner</h4>
                <dl class="mb-6 grid gap-5 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs font-medium text-gray-500">Name</dt><dd class="mt-1">{{ $lib->owner?->name ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-medium text-gray-500">Email</dt><dd class="mt-1">{{ $lib->owner?->email ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-medium text-gray-500">Phone</dt><dd class="mt-1">{{ $lib->owner?->phone ?? '—' }}</dd></div>
                </dl>
                <h4 class="mb-3 text-sm font-semibold text-gray-900">Everyone with access</h4>
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="pb-2 font-medium">Name</th><th class="pb-2 font-medium">Email</th><th class="pb-2 font-medium">Access</th></tr></thead>
                    <tbody>
                        @foreach ($lib->users as $u)
                            <tr class="border-t border-gray-100" wire:key="u-{{ $u->id }}">
                                <td class="py-2">{{ $u->name }}</td><td class="py-2 text-gray-600">{{ $u->email }}</td>
                                <td class="py-2"><x-ui.badge :color="$u->pivot->is_active ? 'green' : 'gray'">{{ $u->pivot->is_active ? 'Active' : 'Disabled' }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

            @elseif ($tab === 'subscription')
                @php $sub = $this->subscription; @endphp
                @if ($sub)
                    @php $eff = $sub->effectiveStatus(); @endphp
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div><p class="text-base font-semibold text-gray-900">{{ $sub->plan_name }}</p><p class="text-xs text-gray-500">{{ ucfirst($sub->billing_cycle) }} · ₹{{ number_format((float) $sub->price, 2) }}</p></div>
                        <div class="flex items-center gap-2">@if ($sub->is_complimentary) <x-ui.badge color="blue" class="px-3 py-1 text-sm">Free access</x-ui.badge> @endif<x-ui.badge :color="match ($eff) { 'active' => 'green', 'trial' => 'blue', 'grace', 'suspended' => 'amber', 'pending' => 'purple', 'expired' => 'red', default => 'gray' }" class="px-3 py-1 text-sm">{{ $eff === 'grace' ? 'Grace period' : ucfirst($eff) }}</x-ui.badge></div>
                    </div>
                    <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                        <div><dt class="text-xs font-medium text-gray-500">Started</dt><dd class="mt-1">{{ $sub->starts_on?->format('d M Y') ?? '—' }}</dd></div>
                        <div><dt class="text-xs font-medium text-gray-500">Expires</dt><dd class="mt-1">{{ $sub->expires_on?->format('d M Y') ?? '—' }}</dd></div>
                        <div><dt class="text-xs font-medium text-gray-500">Grace ends</dt><dd class="mt-1">{{ $sub->graceEndsOn()?->format('d M Y') ?? '—' }}</dd></div>
                    </dl>
                    @if (auth()->user()->hasPlatformPermission('subscriptions.manage'))
                        <a href="{{ route('admin.subscriptions.show', $sub) }}" wire:navigate class="mt-4 inline-flex rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Manage subscription</a>
                        @if (! in_array($sub->status, ['suspended'], true) && ! ($sub->status === 'active' && ! $sub->is_complimentary && $sub->expires_on?->gte(today())))
                            <x-ui.button class="ml-2 mt-4" variant="secondary" wire:click="openFree">{{ $sub->is_complimentary ? 'Extend free access' : 'Grant free access' }}</x-ui.button>
                        @endif
                    @endif
                @else
                    <p class="text-sm text-gray-500">This library has no plan{{ config('billing.require_subscription') ? ', so it can only open its Subscription page. Assign a plan or grant free access to let it work.' : ', so it is unrestricted and nothing is billed.' }}</p>
                    @if (auth()->user()->hasPlatformPermission('subscriptions.manage'))
                        <x-ui.button class="mt-3" wire:click="$set('showAssign', true)">Assign a plan</x-ui.button>
                        <x-ui.button class="ml-2 mt-3" variant="secondary" wire:click="openFree">Grant free access</x-ui.button>
                    @endif
                @endif

                @if (auth()->user()->hasPlatformPermission('subscriptions.manage'))
                    <form wire:submit="savePolicy" class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <h4 class="text-sm font-semibold text-gray-900">When this library has no plan or its plan has expired</h4>
                        <p class="mt-1 text-xs text-gray-500">Applies after the grace period. Suspended and cancelled libraries are always locked. Nothing is ever deleted.</p>
                        <div class="mt-3 flex flex-wrap items-end gap-3">
                            <div class="min-w-64 flex-1">
                                <x-ui.select name="expiryPolicy" label="Access rule" wire:model="expiryPolicy">
                                    <option value="">Platform default ({{ \App\Support\BillingSettings::get('expiry_policy') === 'read_only' ? 'Read-only' : 'Locked' }})</option>
                                    <option value="billing_only">Locked: only the Subscription page works</option>
                                    <option value="read_only">Read-only: can view everything, cannot change anything</option>
                                </x-ui.select>
                            </div>
                            <x-ui.button type="submit">Save rule</x-ui.button>
                        </div>
                    </form>
                @endif

            @elseif ($tab === 'modules')
                <p class="mb-4 text-xs text-gray-500">Modules come from the library's plan, or you can give one separately: as a paid add-on (billed with the subscription at its next payment) or free. Changes apply immediately.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500"><th class="pb-2 font-medium">Module</th><th class="pb-2 font-medium">Status</th><th class="pb-2 font-medium">Details</th><th class="pb-2"></th></tr></thead>
                        <tbody>
                            @foreach ($this->moduleRows as $m)
                                <tr class="border-t border-gray-100" wire:key="mod-{{ $m['key'] }}">
                                    <td class="py-2.5 font-medium text-gray-900">{{ $m['label'] }}</td>
                                    <td class="py-2.5">
                                        @if ($m['source'] === 'plan') <x-ui.badge color="green">In plan</x-ui.badge>
                                        @elseif ($m['source'] === 'addon') <x-ui.badge color="blue">Paid add-on</x-ui.badge>
                                        @elseif ($m['source'] === 'complimentary') <x-ui.badge color="purple">Free</x-ui.badge>
                                        @else <x-ui.badge color="gray">Not enabled</x-ui.badge> @endif
                                    </td>
                                    <td class="py-2.5 text-xs text-gray-500">
                                        @if ($m['grant'] && $m['grant']->type === 'addon') ₹{{ number_format((float) $m['grant']->price_monthly) }}/mo · ₹{{ number_format((float) $m['grant']->priceFor('quarterly')) }}/qtr · ₹{{ number_format((float) $m['grant']->priceFor('annual')) }}/yr
                                        @elseif ($m['grant']) {{ $m['grant']->expires_on ? 'Free until '.$m['grant']->expires_on->format('d M Y') : 'Free until removed' }}
                                        @elseif ($m['source'] === 'plan') Included in the subscription plan @endif
                                    </td>
                                    <td class="py-2.5 text-right whitespace-nowrap">
                                        @if (auth()->user()->hasPlatformPermission('subscriptions.manage'))
                                            @if ($m['source'] !== 'plan')
                                                <button wire:click="openModule('{{ $m['key'] }}', 'grant')" class="font-medium text-brand-600 hover:text-brand-800">{{ $m['grant'] ? 'Change' : 'Enable' }}</button>
                                            @endif
                                            @if ($m['grant']) <button wire:click="openModule('{{ $m['key'] }}', 'revoke')" class="ml-3 font-medium text-red-600 hover:text-red-800">Disable</button> @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

            @elseif ($tab === 'payments')
                @php $pays = $this->subscriptionPayments; @endphp
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="pb-2 font-medium">Submitted</th><th class="pb-2 font-medium">Reference</th><th class="pb-2 text-right font-medium">Amount</th><th class="pb-2 pl-4 font-medium">Status</th></tr></thead>
                    <tbody>
                        @forelse ($pays as $pay)
                            <tr class="border-t border-gray-100" wire:key="lp-{{ $pay->id }}"><td class="py-2">{{ $pay->created_at->format('d M Y') }}</td><td class="py-2 font-medium">{{ $pay->reference }}</td><td class="py-2 text-right">₹{{ number_format((float) $pay->amount, 2) }}</td>
                                <td class="py-2 pl-4"><x-ui.badge :color="match ($pay->status) { 'approved' => 'green', 'pending' => 'blue', 'info_requested' => 'amber', default => 'red' }">{{ \App\Models\SubscriptionPayment::STATUSES[$pay->status] }}</x-ui.badge></td></tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">No subscription payments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>

            @elseif ($tab === 'usage')
                @php
                    $usage = [
                        'Students' => \App\Models\Student::withoutGlobalScopes()->where('library_id', $lib->id)->count(),
                        'Seats' => \App\Models\Seat::withoutGlobalScopes()->where('library_id', $lib->id)->count(),
                        'Staff accounts' => $lib->users->count(),
                    ];
                @endphp
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach ($usage as $label => $count)
                        <div class="rounded-lg border border-gray-200 p-4"><p class="text-xs text-gray-500">{{ $label }}</p><p class="text-2xl font-semibold">{{ number_format($count) }}</p></div>
                    @endforeach
                </div>
                @php $limits = $this->subscription; @endphp
                @if ($limits)
                    <p class="mt-4 text-xs text-gray-500">Plan limits: {{ $limits->max_students ?? 'unlimited' }} students · {{ $limits->max_seats ?? 'unlimited' }} seats · {{ $limits->max_staff ?? 'unlimited' }} staff accounts ({{ $limits->plan_name }}).</p>
                @else
                    <p class="mt-4 text-xs text-gray-500">No plan assigned, so there are no limits.</p>
                @endif

            @elseif ($tab === 'activity')
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="pb-2 font-medium">When</th><th class="pb-2 font-medium">Action</th><th class="pb-2 font-medium">By</th><th class="pb-2 font-medium">Details</th></tr></thead>
                    <tbody>
                        @forelse ($lib->auditLogs as $log)
                            <tr class="border-t border-gray-100" wire:key="log-{{ $log->id }}">
                                <td class="py-2 text-gray-500">{{ $log->created_at->format('d M Y, H:i') }}</td>
                                <td class="py-2"><x-ui.badge>{{ $log->action }}</x-ui.badge></td>
                                <td class="py-2">{{ $log->actor?->name ?? 'System' }}</td>
                                <td class="py-2 text-xs text-gray-600">{{ collect($log->properties ?? [])->filter(fn ($v) => filled($v))->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? $v : json_encode($v)))->implode(' · ') ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">No activity recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>

            @elseif ($tab === 'notes')
                <p class="mb-4 text-xs text-gray-500">Internal only — never visible to the library.</p>
                <ul class="space-y-3">
                    @forelse ($lib->notes as $note)
                        <li class="rounded-lg border border-gray-200 p-3 text-sm" wire:key="note-{{ $note->id }}">
                            <p class="whitespace-pre-line text-gray-800">{{ $note->body }}</p>
                            <p class="mt-2 text-xs text-gray-400">{{ $note->author?->name ?? '—' }} · {{ $note->created_at->format('d M Y, H:i') }}</p>
                        </li>
                    @empty
                        <li class="py-4 text-center text-sm text-gray-400">No notes yet.</li>
                    @endforelse
                </ul>
            @endif
        </div>
    </div>

    {{-- status change (reason required) --}}
    @if ($statusTarget)
        <x-ui.modal :title="($labels[$statusTarget] ?? 'Change status').': '.$lib->name" close="cancelStatus()" wire:key="status-modal">
            <p class="mb-4 text-sm text-gray-600">
                {{ $statusTarget === 'active' ? 'The library and its staff will be able to sign in.' : 'All data is kept. Users of this library lose access immediately.' }}
            </p>
            <form wire:submit="confirmStatus" class="space-y-4">
                <x-ui.textarea name="reason" label="Reason" required wire:model="reason" />
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" wire:click="cancelStatus">Cancel</x-ui.button>
                    <x-ui.button type="submit" :variant="$statusTarget === 'suspended' ? 'warning' : ($statusTarget === 'inactive' ? 'danger' : 'primary')">Confirm</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($showAssign)
        <x-ui.modal title="Assign a plan" close="$set('showAssign', false)" wire:key="assign-modal">
            <form wire:submit="assignPlanNow" class="space-y-4">
                <x-ui.select name="assignPlan" label="Plan" required wire:model.live="assignPlan"><option value="">Choose…</option>@foreach ($this->plans as $p) <option value="{{ $p->id }}">{{ $p->name }}</option> @endforeach</x-ui.select>
                @php $chosen = $this->plans->firstWhere('id', (int) $assignPlan); @endphp
                @if ($chosen)
                    <x-ui.select name="assignCycle" label="Billing cycle" wire:model="assignCycle">@foreach ($chosen->cycles() as $c) <option value="{{ $c }}">{{ \App\Models\SubscriptionPlan::CYCLES[$c] }}</option> @endforeach</x-ui.select>
                    @if ($chosen->trial_days > 0) <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="assignTrial" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> Start with a {{ $chosen->trial_days }}-day trial</label> @endif
                @endif
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="$set('showAssign', false)">Cancel</x-ui.button><x-ui.button type="submit">Assign plan</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    @if ($showModule)
        <x-ui.modal :title="($modAction === 'revoke' ? 'Disable ' : 'Enable ').(\App\Models\SubscriptionPlan::MODULES[$modName] ?? $modName)" close="$set('showModule', false)" wire:key="module-modal">
            <form wire:submit="saveModule" class="space-y-4">
                @if ($modAction === 'grant')
                    <x-ui.select name="modType" label="How" wire:model.live="modType">
                        <option value="addon">Paid add-on (billed with the subscription)</option>
                        <option value="complimentary">Free</option>
                    </x-ui.select>
                    @if ($modType === 'addon')
                        <div class="grid gap-3 sm:grid-cols-3">
                            <x-ui.input name="modMonthly" label="Monthly (₹)" type="number" step="0.01" min="0" wire:model="modMonthly" />
                            <x-ui.input name="modQuarterly" label="Quarterly (₹)" type="number" step="0.01" min="0" wire:model="modQuarterly" hint="Blank = 3 × monthly" />
                            <x-ui.input name="modAnnual" label="Annual (₹)" type="number" step="0.01" min="0" wire:model="modAnnual" hint="Blank = 12 × monthly" />
                        </div>
                        <p class="text-xs text-gray-500">Prefilled from the platform's standard add-on prices. It works straight away and is added to the library's next subscription payment.</p>
                    @else
                        <x-ui.input name="modExpires" label="Free until (optional)" type="date" wire:model="modExpires" hint="Leave empty to keep it free until you disable it." />
                    @endif
                @else
                    <p class="text-sm text-gray-700">The library loses access to this module straight away. Its data is kept, and it is not billed from the next payment.</p>
                @endif
                <x-ui.textarea name="modReason" label="Reason (kept in the history)" required wire:model="modReason" rows="3" />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="$set('showModule', false)">Cancel</x-ui.button><x-ui.button type="submit" :variant="$modAction === 'revoke' ? 'danger' : 'primary'">{{ $modAction === 'revoke' ? 'Disable module' : 'Enable module' }}</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    @if ($showFree)
        <x-ui.modal title="Grant free access" close="$set('showFree', false)" wire:key="free-modal">
            <form wire:submit="grantFree" class="space-y-4">
                <p class="text-xs text-gray-500">The library works normally, with the limits and modules of the plan you pick, and nothing is billed. When it ends, the normal grace period and expiry rules apply.</p>
                <x-ui.select name="freePlan" label="Plan limits to apply" required wire:model="freePlan"><option value="">Choose…</option>@foreach ($this->plans as $p) <option value="{{ $p->id }}">{{ $p->name }}</option> @endforeach</x-ui.select>
                <x-ui.select name="freeDays" label="Free for" required wire:model.live="freeDays">
                    @foreach (\App\Actions\Billing\GrantFreePeriod::PRESETS as $d => $label) <option value="{{ $d }}">{{ $label }}</option> @endforeach
                    <option value="custom">Custom number of days…</option>
                </x-ui.select>
                @if ($freeDays === 'custom') <x-ui.input name="freeCustom" label="Number of days" type="number" min="1" max="730" required wire:model="freeCustom" /> @endif
                <x-ui.textarea name="freeReason" label="Reason (kept in the history)" required wire:model="freeReason" rows="3" />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="$set('showFree', false)">Cancel</x-ui.button><x-ui.button type="submit">Grant free access</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    @if ($showNote)
        <x-ui.modal title="Add internal note" close="$set('showNote', false)" wire:key="note-modal">
            <form wire:submit="saveNote" class="space-y-4">
                <x-ui.textarea name="noteBody" label="Note" required wire:model="noteBody" rows="5" />
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" wire:click="$set('showNote', false)">Cancel</x-ui.button>
                    <x-ui.button type="submit">Save note</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
