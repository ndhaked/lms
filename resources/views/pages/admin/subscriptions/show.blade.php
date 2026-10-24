<?php

use App\Actions\Billing\ManageSubscription;
use App\Exceptions\BusinessRuleException;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    public int $subscriptionId;

    public string $modal = '';          // activate | extend | suspend | reactivate | cancel | plan
    public string $reason = '';
    public string $date = '';
    public string $planId = '';
    public string $cycle = 'monthly';
    public string $applies = 'now';

    public function mount(string $subscription): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('subscriptions.manage'), 403);
        $this->subscriptionId = Subscription::findOrFail($subscription)->id;
    }

    #[Computed]
    public function sub(): Subscription
    {
        return Subscription::with(['library', 'plan', 'nextPlan', 'payments.reviewer', 'payments.proofs', 'invoices', 'events.actor'])->findOrFail($this->subscriptionId);
    }

    #[Computed]
    public function plans()
    {
        return SubscriptionPlan::where('status', 'active')->orderBy('name')->get();
    }

    public function open(string $modal): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('subscriptions.manage'), 403);
        $this->reset(['reason', 'date']);
        $this->resetErrorBag();
        $this->modal = $modal;
        $this->planId = (string) $this->sub->subscription_plan_id;
        $this->cycle = $this->sub->billing_cycle;
        $this->applies = 'now';
        $this->date = in_array($modal, ['activate', 'extend'], true) ? ($this->sub->coveredUntil() ?? today())->copy()->addMonth()->toDateString() : '';
    }

    public function close(): void
    {
        $this->modal = '';
    }

    public function submit(): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:500']]);
        $actions = app(ManageSubscription::class);
        $s = $this->sub;

        try {
            match ($this->modal) {
                'suspend' => $actions->suspend($s, $this->reason),
                'reactivate' => $actions->reactivate($s, $this->reason),
                'cancel' => $actions->cancel($s, $this->reason),
                'activate' => $actions->activate($s, $this->parseDate(), $this->reason),
                'extend' => $actions->extend($s, $this->parseDate(), $this->reason),
                'plan' => $actions->changePlan($s, SubscriptionPlan::findOrFail($this->planId), $this->cycle, $this->applies, $this->reason),
                default => throw new BusinessRuleException('Unknown action.'),
            };
        } catch (BusinessRuleException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }

        $this->modal = '';
        unset($this->sub);
        $this->dispatch('notify', message: 'Subscription updated.');
    }

    protected function parseDate(): Carbon
    {
        $this->validate(['date' => ['required', 'date']], [], ['date' => 'date']);

        return Carbon::parse($this->date);
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title :title="$this->sub->library->name.' — subscription'" /></x-slot:header>

    @php
        $s = $this->sub;
        $eff = $s->effectiveStatus();
        $color = fn ($st) => match ($st) { 'active' => 'green', 'trial' => 'blue', 'grace' => 'amber', 'pending' => 'purple', 'suspended' => 'amber', 'expired' => 'red', default => 'gray' };
        $payColor = fn ($st) => match ($st) { 'approved' => 'green', 'pending' => 'blue', 'info_requested' => 'amber', 'rejected' => 'red', default => 'gray' };
        $can = ! in_array($s->status, ['cancelled'], true);
        $titles = ['activate' => 'Activate subscription', 'extend' => 'Extend expiry', 'suspend' => 'Suspend subscription', 'reactivate' => 'Reactivate subscription', 'cancel' => 'Cancel subscription', 'plan' => 'Change plan'];
    @endphp

    <div class="flex flex-wrap items-center gap-2">
        <x-ui.badge :color="$color($eff)" class="mr-1 px-3 py-1 text-sm">{{ $eff === 'grace' ? 'Grace period' : ucfirst($eff) }}</x-ui.badge>
        <a href="{{ route('admin.libraries.show', $s->library_id) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-800">{{ $s->library->code }} · View library</a>
        <span class="flex-1"></span>
        @if ($can)
            @if (in_array($s->status, ['pending', 'trial', 'expired'], true)) <x-ui.button wire:click="open('activate')">Activate</x-ui.button> @endif
            @if (! in_array($s->status, ['suspended'], true)) <x-ui.button variant="secondary" wire:click="open('extend')">Extend expiry</x-ui.button> @endif
            <x-ui.button variant="secondary" wire:click="open('plan')">Change plan</x-ui.button>
            @if ($s->status === 'suspended') <x-ui.button wire:click="open('reactivate')">Reactivate</x-ui.button>
            @else <x-ui.button variant="warning" wire:click="open('suspend')">Suspend</x-ui.button> @endif
            <x-ui.button variant="danger" wire:click="open('cancel')">Cancel</x-ui.button>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-xl border border-gray-200 bg-white p-5 lg:col-span-2">
            <h3 class="mb-4 text-sm font-semibold text-gray-900">Plan &amp; period</h3>
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
                @foreach ([
                    'Plan' => $s->plan_name, 'Billing cycle' => ucfirst($s->billing_cycle), 'Price' => '₹'.number_format((float) $s->price, 2),
                    'Started' => $s->starts_on?->format('d M Y'), 'Trial ends' => $s->trial_ends_on?->format('d M Y'), 'Expires' => $s->expires_on?->format('d M Y'),
                    'Grace period' => $s->grace_days.' days'.($s->graceEndsOn() ? ' (to '.$s->graceEndsOn()->format('d M Y').')' : ''),
                    'Limits' => ($s->max_students ?? '∞').' students · '.($s->max_seats ?? '∞').' seats · '.($s->max_staff ?? '∞').' staff',
                    'Modules' => collect(\App\Models\SubscriptionPlan::MODULES)->filter(fn ($l, $k) => $s->hasModule($k))->implode(', ') ?: 'Core only',
                ] as $label => $value)
                    <div><dt class="text-xs font-medium text-gray-500">{{ $label }}</dt><dd class="mt-1 text-gray-900">{{ $value ?: '—' }}</dd></div>
                @endforeach
            </dl>
            @if ($s->nextPlan)
                <p class="mt-4 rounded-lg bg-brand-50 px-3 py-2 text-xs text-brand-800">Will switch to <strong>{{ $s->nextPlan->name }}</strong> at the next renewal.</p>
            @endif
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-3 text-sm font-semibold text-gray-900">Invoices</h3>
            <ul class="divide-y divide-gray-100 text-sm">
                @forelse ($s->invoices->sortByDesc('id') as $inv)
                    <li class="flex items-center justify-between py-2" wire:key="inv-{{ $inv->id }}"><a href="{{ route('admin.subscription-invoices.show', $inv) }}" target="_blank" class="font-medium text-brand-600 hover:text-brand-800">{{ $inv->invoice_no }}</a><span class="text-gray-600">₹{{ number_format((float) $inv->amount, 2) }}</span></li>
                @empty
                    <li class="py-4 text-center text-gray-400">No invoices yet.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <section class="rounded-xl border border-gray-200 bg-white p-5">
        <h3 class="mb-3 text-sm font-semibold text-gray-900">Payments</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-gray-500"><th class="pb-2 font-medium">Submitted</th><th class="pb-2 font-medium">Reference</th><th class="pb-2 text-right font-medium">Amount</th><th class="pb-2 font-medium pl-4">Cycle</th><th class="pb-2 font-medium">Status</th><th class="pb-2 font-medium">Period covered</th><th class="pb-2 font-medium">Decided by</th></tr></thead>
                <tbody>
                    @forelse ($s->payments->sortByDesc('id') as $p)
                        <tr class="border-t border-gray-100" wire:key="pay-{{ $p->id }}">
                            <td class="py-2">{{ $p->created_at->format('d M Y') }}</td><td class="py-2 font-medium">{{ $p->reference }}</td>
                            <td class="py-2 text-right">₹{{ number_format((float) $p->amount, 2) }}</td><td class="py-2 pl-4">{{ ucfirst($p->billing_cycle) }}</td>
                            <td class="py-2"><x-ui.badge :color="$payColor($p->status)">{{ \App\Models\SubscriptionPayment::STATUSES[$p->status] }}</x-ui.badge></td>
                            <td class="py-2 text-gray-600">{{ $p->period_starts_on ? $p->period_starts_on->format('d M Y').' – '.$p->period_ends_on->format('d M Y') : '—' }}</td>
                            <td class="py-2 text-xs text-gray-500">{{ $p->reviewer?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-400">No payments yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-5">
        <h3 class="mb-3 text-sm font-semibold text-gray-900">History</h3>
        <ul class="divide-y divide-gray-100 text-sm">
            @forelse ($s->events as $e)
                <li class="flex flex-wrap items-center gap-3 py-2" wire:key="ev-{{ $e->id }}">
                    <span class="w-36 text-xs text-gray-400">{{ $e->created_at->format('d M Y, H:i') }}</span>
                    <x-ui.badge>{{ str_replace('_', ' ', $e->type) }}</x-ui.badge>
                    @if ($e->from_status || $e->to_status) <span class="text-xs text-gray-500">{{ $e->from_status ?? '—' }} → {{ $e->to_status ?? '—' }}</span> @endif
                    <span class="text-gray-700">{{ $e->reason }}</span>
                    <span class="ml-auto text-xs text-gray-400">{{ $e->actor?->name ?? 'System' }}</span>
                </li>
            @empty
                <li class="py-6 text-center text-gray-400">No history.</li>
            @endforelse
        </ul>
    </section>

    @if ($modal)
        <x-ui.modal :title="$titles[$modal]" close="close()" max="max-w-lg" wire:key="sub-modal">
            <form wire:submit="submit" class="space-y-4">
                @if (in_array($modal, ['activate', 'extend']))
                    <x-ui.input name="date" :label="$modal === 'activate' ? 'Active until' : 'New expiry date'" type="date" required wire:model="date" hint="{{ $s->coveredUntil() ? 'Currently covered until '.$s->coveredUntil()->format('d M Y').'.' : '' }}" />
                @endif
                @if ($modal === 'plan')
                    <x-ui.select name="planId" label="New plan" wire:model="planId">@foreach ($this->plans as $p) <option value="{{ $p->id }}">{{ $p->name }}</option> @endforeach</x-ui.select>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.select name="cycle" label="Billing cycle" wire:model="cycle">@foreach (\App\Models\SubscriptionPlan::CYCLES as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach</x-ui.select>
                        <x-ui.select name="applies" label="Takes effect" wire:model="applies"><option value="now">Immediately</option><option value="renewal">At the next renewal</option></x-ui.select>
                    </div>
                @endif
                @if (in_array($modal, ['suspend', 'cancel']))
                    <p class="text-sm text-gray-600">The library keeps all its data. After {{ $modal === 'cancel' ? 'cancellation' : 'suspension' }} only its Subscription page works until this is resolved.</p>
                @endif
                <x-ui.textarea name="reason" label="Reason (recorded in the history)" required wire:model="reason" />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="close">Cancel</x-ui.button><x-ui.button type="submit" :variant="in_array($modal, ['cancel']) ? 'danger' : ($modal === 'suspend' ? 'warning' : 'primary')">Confirm</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
