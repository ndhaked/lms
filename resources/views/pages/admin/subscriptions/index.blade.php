<?php

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $plan = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('subscriptions.manage'), 403);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'status', 'plan'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function plans()
    {
        return SubscriptionPlan::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function rows()
    {
        // Only each library's CURRENT subscription (its latest row) is listed here.
        $latest = Subscription::selectRaw('max(id)')->groupBy('library_id');

        return Subscription::query()
            ->with(['library', 'payments' => fn ($q) => $q->latest('id')->limit(1)])
            ->whereIn('id', $latest)
            ->when($this->search !== '', fn ($q) => $q->whereHas('library', fn ($l) => $l->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%")))
            ->when($this->plan !== '', fn ($q) => $q->where('subscription_plan_id', $this->plan))
            ->when($this->status !== '', function ($q) {
                // "expired" and "grace" depend on today's date, so they are derived from the dates.
                return match ($this->status) {
                    'grace' => $q->whereIn('status', ['trial', 'active'])->whereRaw('coalesce(expires_on, trial_ends_on) < ?', [today()->toDateString()])
                        ->whereRaw('date_add(coalesce(expires_on, trial_ends_on), interval grace_days day) >= ?', [today()->toDateString()]),
                    'expired' => $q->where(fn ($q) => $q->where('status', 'expired')->orWhere(fn ($q) => $q->whereIn('status', ['trial', 'active'])
                        ->whereRaw('date_add(coalesce(expires_on, trial_ends_on), interval grace_days day) < ?', [today()->toDateString()]))),
                    'active' => $q->where('status', 'active')->whereDate('expires_on', '>=', today()),
                    'trial' => $q->where('status', 'trial')->whereDate('trial_ends_on', '>=', today()),
                    default => $q->where('status', $this->status),
                };
            })
            ->orderBy('expires_on')
            ->paginate(15);
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Subscriptions" subtitle="Every library's current subscription" /></x-slot:header>

    @php
        $color = fn ($s) => match ($s) { 'active' => 'green', 'trial' => 'blue', 'grace' => 'amber', 'pending' => 'purple', 'suspended' => 'amber', 'expired' => 'red', default => 'gray' };
        $payColor = fn ($s) => match ($s) { 'approved' => 'green', 'pending' => 'blue', 'info_requested' => 'amber', 'rejected' => 'red', default => 'gray' };
    @endphp

    <div class="flex flex-wrap items-center gap-3">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search library or code…" class="w-60 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="">Any status</option>
            @foreach (['trial' => 'Trial', 'pending' => 'Pending', 'active' => 'Active', 'grace' => 'In grace period', 'expired' => 'Expired', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'] as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach
        </select>
        <select wire:model.live="plan" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="">Any plan</option>
            @foreach ($this->plans as $p) <option value="{{ $p->id }}">{{ $p->name }}</option> @endforeach
        </select>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Library</th><th class="px-4 py-3 font-medium">Plan</th><th class="px-4 py-3 font-medium">Started</th><th class="px-4 py-3 font-medium">Trial ends</th><th class="px-4 py-3 font-medium">Expires</th><th class="px-4 py-3 font-medium">Grace ends</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3 font-medium">Latest payment</th><th class="px-4 py-3 text-right font-medium">Amount due</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->rows as $s)
                        @php
                            $eff = $s->effectiveStatus();
                            $last = $s->payments->first();
                            $due = in_array($eff, ['pending', 'grace', 'expired'], true) || ($s->daysLeft() !== null && $s->daysLeft() <= 7 && $eff === 'active') ? $s->price : null;
                        @endphp
                        <tr wire:key="sub-{{ $s->id }}">
                            <td class="px-4 py-3"><a href="{{ route('admin.subscriptions.show', $s) }}" wire:navigate class="font-medium text-gray-900 hover:text-brand-700">{{ $s->library->name }}</a><div class="text-xs text-gray-400">{{ $s->library->code }}</div></td>
                            <td class="px-4 py-3">{{ $s->plan_name }}<div class="text-xs text-gray-400">{{ ucfirst($s->billing_cycle) }} · ₹{{ number_format((float) $s->price, 2) }}</div></td>
                            <td class="px-4 py-3 text-gray-600">{{ $s->starts_on?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $s->trial_ends_on?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $s->expires_on?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $s->graceEndsOn()?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$color($eff)">{{ $eff === 'grace' ? 'Grace period' : ucfirst($eff) }}</x-ui.badge></td>
                            <td class="px-4 py-3">@if ($last)<x-ui.badge :color="$payColor($last->status)">{{ \App\Models\SubscriptionPayment::STATUSES[$last->status] }}</x-ui.badge>@else<span class="text-gray-400">—</span>@endif</td>
                            <td class="px-4 py-3 text-right {{ $due ? 'font-semibold text-red-600' : 'text-gray-400' }}">{{ $due !== null ? '₹'.number_format((float) $due, 2) : '—' }}</td>
                            <td class="px-4 py-3 text-right"><a href="{{ route('admin.subscriptions.show', $s) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-800">Manage</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-4 py-10 text-center text-gray-400">No subscriptions match. Assign a plan from a library's page or when registering a library.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->rows->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->rows->links() }}</div> @endif
    </div>
</div>
