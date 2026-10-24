<?php

use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Seat;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    #[Computed]
    public function byStatus()
    {
        return Library::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
    }

    /** Each library's current (latest) subscription. */
    #[Computed]
    public function currentSubscriptions()
    {
        return Subscription::with('library')->whereIn('id', Subscription::selectRaw('max(id)')->groupBy('library_id'))->get();
    }

    #[Computed]
    public function subscriptionCounts(): array
    {
        $by = $this->currentSubscriptions->groupBy(fn ($s) => $s->effectiveStatus())->map->count();

        return ['trial' => $by['trial'] ?? 0, 'expired' => ($by['expired'] ?? 0), 'grace' => $by['grace'] ?? 0];
    }

    // Platform-wide totals read every library, so the tenant scope is removed on purpose.
    #[Computed]
    public function students(): int
    {
        return Student::withoutGlobalScopes()->count();
    }

    #[Computed]
    public function seats(): int
    {
        return Seat::withoutGlobalScopes()->count();
    }

    /** Only APPROVED subscription payments count as revenue; submitted or rejected ones never do. */
    #[Computed]
    public function revenue(): float
    {
        return (float) SubscriptionPayment::where('status', SubscriptionPayment::APPROVED)->sum('amount');
    }

    #[Computed]
    public function pendingApprovals(): int
    {
        return SubscriptionPayment::where('status', SubscriptionPayment::PENDING)->count();
    }

    #[Computed]
    public function newest()
    {
        return Library::with('owner')->latest()->limit(5)->get();
    }

    #[Computed]
    public function suspended()
    {
        return Library::where('status', Library::SUSPENDED)->latest('updated_at')->limit(5)->get();
    }

    #[Computed]
    public function recentPayments()
    {
        return SubscriptionPayment::with('library')->latest('id')->limit(6)->get();
    }

    #[Computed]
    public function expiring()
    {
        return $this->currentSubscriptions
            ->filter(fn ($s) => in_array($s->effectiveStatus(), ['active', 'trial'], true) && $s->daysLeft() !== null && $s->daysLeft() <= 14)
            ->sortBy('expires_on')->take(6)->values();
    }

    #[Computed]
    public function activity()
    {
        return AuditLog::with(['actor', 'library'])->latest('created_at')->latest('id')->limit(8)->get();
    }

    /** New libraries per month for the last 12 months. */
    #[Computed]
    public function growth(): array
    {
        $start = now()->startOfMonth()->subMonths(11);
        $counts = Library::where('created_at', '>=', $start)->pluck('created_at')->countBy(fn ($d) => $d->format('Y-m'));

        return collect(range(0, 11))->map(function ($i) use ($start, $counts) {
            $m = $start->copy()->addMonths($i);

            return ['label' => $m->format('M y'), 'value' => $counts[$m->format('Y-m')] ?? 0];
        })->all();
    }

    /** Approved subscription payments per month for the last 12 months. */
    #[Computed]
    public function revenueByMonth(): array
    {
        $start = now()->startOfMonth()->subMonths(11);
        $sums = SubscriptionPayment::where('status', SubscriptionPayment::APPROVED)->where('reviewed_at', '>=', $start)->get(['reviewed_at', 'amount'])
            ->groupBy(fn ($p) => $p->reviewed_at->format('Y-m'))->map->sum('amount');

        return collect(range(0, 11))->map(function ($i) use ($start, $sums) {
            $m = $start->copy()->addMonths($i);

            return ['label' => $m->format('M y'), 'value' => (float) ($sums[$m->format('Y-m')] ?? 0)];
        })->all();
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Dashboard" subtitle="Overview of the platform and its libraries" /></x-slot:header>

    @php
        $s = $this->byStatus;
        $sc = $this->subscriptionCounts;
        $badge = fn ($st) => match ($st) { 'active' => 'green', 'pending' => 'blue', 'suspended' => 'amber', default => 'gray' };

        // simple line chart helper (series of ['label','value'])
        $chart = function (array $series) {
            $max = max(1, max(array_column($series, 'value')));
            [$w, $h, $pl, $pb, $pt] = [520, 190, 38, 26, 10];
            $x = fn ($i) => $pl + $i * (($w - $pl - 10) / 11);
            $y = fn ($v) => $pt + ($h - $pt - $pb) * (1 - $v / $max);
            $path = collect($series)->map(fn ($g, $i) => ($i ? 'L' : 'M').round($x($i), 1).' '.round($y($g['value']), 1))->join(' ');

            return compact('w', 'h', 'pl', 'max', 'x', 'y', 'path');
        };
        $growth = $chart($this->growth);
        $rev = $chart($this->revenueByMonth);
    @endphp

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Registered libraries" :value="$s->sum()" icon="library" tone="green" />
        <x-ui.stat label="Active libraries" :value="$s['active'] ?? 0" icon="check-circle" tone="green" />
        <x-ui.stat label="Libraries on trial" :value="$sc['trial']" icon="clock" tone="blue" />
        <x-ui.stat label="Pending activations" :value="$s['pending'] ?? 0" icon="clock" tone="purple" />
        <x-ui.stat label="Expired subscriptions" :value="$sc['expired']" icon="alert" tone="red" :hint="$sc['grace'].' in grace period'" />
        <x-ui.stat label="Suspended libraries" :value="$s['suspended'] ?? 0" icon="ban" tone="orange" />
        <x-ui.stat label="Students (all libraries)" :value="number_format($this->students)" icon="users" tone="purple" />
        <x-ui.stat label="Registered seats" :value="number_format($this->seats)" icon="seat" tone="blue" />
        <x-ui.stat label="Subscription revenue" :value="'₹'.number_format($this->revenue, 2)" icon="cash" tone="green" hint="Approved payments only" />
        <a href="{{ route('admin.payment-approvals.index') }}" wire:navigate class="block"><x-ui.stat label="Pending payment approvals" :value="$this->pendingApprovals" icon="check-badge" :tone="$this->pendingApprovals ? 'orange' : 'gray'" hint="Open the queue" /></a>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-gray-900">Monthly subscription revenue</h3>
            <p class="mb-2 text-xs text-gray-500">Approved payments per month (last 12 months)</p>
            <svg viewBox="0 0 {{ $rev['w'] }} {{ $rev['h'] }}" class="w-full" role="img" aria-label="Monthly subscription revenue">
                @foreach ([0, 0.5, 1] as $t)
                    <line x1="{{ $rev['pl'] }}" x2="{{ $rev['w'] - 10 }}" y1="{{ ($rev['y'])($t * $rev['max']) }}" y2="{{ ($rev['y'])($t * $rev['max']) }}" stroke="#eef0f3" />
                    <text x="{{ $rev['pl'] - 6 }}" y="{{ ($rev['y'])($t * $rev['max']) + 3 }}" text-anchor="end" font-size="9" fill="#6b7280">{{ $rev['max'] >= 1000 ? round($t * $rev['max'] / 1000, 1).'K' : round($t * $rev['max']) }}</text>
                @endforeach
                @foreach ($this->revenueByMonth as $i => $g)
                    @php $bx = ($rev['x'])($i); $by = ($rev['y'])($g['value']); @endphp
                    <rect x="{{ $bx - 9 }}" y="{{ $by }}" width="18" height="{{ max(0, 164 - $by) }}" rx="3" fill="#2f9e5f" opacity=".85"><title>{{ $g['label'] }}: ₹{{ number_format($g['value'], 2) }}</title></rect>
                    <text x="{{ $bx }}" y="{{ $rev['h'] - 8 }}" text-anchor="middle" font-size="8.5" fill="#6b7280">{{ $g['label'] }}</text>
                @endforeach
            </svg>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-gray-900">Library growth</h3>
            <p class="mb-2 text-xs text-gray-500">New libraries registered per month (last 12 months)</p>
            <svg viewBox="0 0 {{ $growth['w'] }} {{ $growth['h'] }}" class="w-full" role="img" aria-label="New libraries per month">
                @foreach ([0, 0.5, 1] as $t)
                    <line x1="{{ $growth['pl'] }}" x2="{{ $growth['w'] - 10 }}" y1="{{ ($growth['y'])($t * $growth['max']) }}" y2="{{ ($growth['y'])($t * $growth['max']) }}" stroke="#eef0f3" />
                    <text x="{{ $growth['pl'] - 6 }}" y="{{ ($growth['y'])($t * $growth['max']) + 3 }}" text-anchor="end" font-size="9" fill="#6b7280">{{ round($t * $growth['max'], 1) }}</text>
                @endforeach
                @foreach ($this->growth as $i => $g)
                    <text x="{{ ($growth['x'])($i) }}" y="{{ $growth['h'] - 8 }}" text-anchor="middle" font-size="8.5" fill="#6b7280">{{ $g['label'] }}</text>
                @endforeach
                <path d="{{ $growth['path'] }}" fill="none" stroke="#2f9e5f" stroke-width="2" stroke-linejoin="round" />
                @foreach ($this->growth as $i => $g)
                    <circle cx="{{ ($growth['x'])($i) }}" cy="{{ ($growth['y'])($g['value']) }}" r="3" fill="#fff" stroke="#2f9e5f" stroke-width="1.6"><title>{{ $g['label'] }}: {{ $g['value'] }}</title></circle>
                @endforeach
            </svg>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="mb-3 flex items-center justify-between"><h3 class="text-sm font-semibold text-gray-900">Recent subscription payments</h3><a href="{{ route('admin.payment-approvals.index', ['status' => '']) }}" wire:navigate class="text-xs font-medium text-brand-600">View all</a></div>
            <ul class="divide-y divide-gray-100 text-xs">
                @forelse ($this->recentPayments as $p)
                    <li class="flex items-center justify-between py-2" wire:key="rp-{{ $p->id }}">
                        <span><span class="font-medium text-gray-900">{{ $p->library->name }}</span> <span class="text-gray-400">· {{ $p->reference }}</span></span>
                        <span class="flex items-center gap-2"><span class="font-semibold">₹{{ number_format((float) $p->amount, 2) }}</span><x-ui.badge :color="match ($p->status) { 'approved' => 'green', 'pending' => 'blue', 'info_requested' => 'amber', default => 'red' }">{{ \App\Models\SubscriptionPayment::STATUSES[$p->status] }}</x-ui.badge></span>
                    </li>
                @empty
                    <li class="py-6 text-center text-gray-400">No subscription payments yet.</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-3 text-sm font-semibold text-gray-900">Upcoming subscription expiries</h3>
            <ul class="divide-y divide-gray-100 text-xs">
                @forelse ($this->expiring as $sub)
                    <li class="flex items-center justify-between py-2" wire:key="ex-{{ $sub->id }}">
                        <a href="{{ route('admin.subscriptions.show', $sub) }}" wire:navigate class="font-medium text-gray-900 hover:text-brand-700">{{ $sub->library->name }} <span class="text-gray-400">· {{ $sub->plan_name }}</span></a>
                        <span class="{{ $sub->daysLeft() <= 3 ? 'font-semibold text-red-600' : 'text-amber-600' }}">{{ $sub->coveredUntil()->format('d M Y') }} ({{ max($sub->daysLeft(), 0) }}d)</span>
                    </li>
                @empty
                    <li class="py-6 text-center text-gray-400">Nothing expires in the next 14 days.</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="mb-3 flex items-center justify-between"><h3 class="text-sm font-semibold text-gray-900">New library registrations</h3><a href="{{ route('admin.libraries.index') }}" wire:navigate class="text-xs font-medium text-brand-600">View all</a></div>
            <table class="w-full text-xs">
                <thead><tr class="text-left text-gray-500"><th class="pb-2 font-medium">Library</th><th class="pb-2 font-medium">Owner</th><th class="pb-2 font-medium">Status</th><th class="pb-2 font-medium">Registered</th></tr></thead>
                <tbody>
                    @forelse ($this->newest as $lib)
                        <tr class="border-t border-gray-100" wire:key="n-{{ $lib->id }}">
                            <td class="py-2"><a href="{{ route('admin.libraries.show', $lib) }}" wire:navigate class="font-medium text-gray-900 hover:text-brand-700">{{ $lib->name }}</a><div class="text-[11px] text-gray-400">{{ $lib->code }}</div></td>
                            <td class="py-2 text-gray-600">{{ $lib->owner?->name ?? '—' }}</td>
                            <td class="py-2"><x-ui.badge :color="$badge($lib->status)">{{ \App\Models\Library::STATUSES[$lib->status] }}</x-ui.badge></td>
                            <td class="py-2 text-gray-500">{{ $lib->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-400">No libraries yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-3 text-sm font-semibold text-gray-900">Recently suspended libraries</h3>
            <ul class="divide-y divide-gray-100 text-xs">
                @forelse ($this->suspended as $lib)
                    <li class="flex items-center justify-between py-2" wire:key="s-{{ $lib->id }}">
                        <a href="{{ route('admin.libraries.show', $lib) }}" wire:navigate class="font-medium text-gray-900 hover:text-brand-700">{{ $lib->name }} <span class="text-gray-400">{{ $lib->code }}</span></a>
                        <span class="text-gray-500">{{ $lib->updated_at->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="py-6 text-center text-gray-400">No suspended libraries.</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-5 xl:col-span-2">
            <div class="mb-3 flex items-center justify-between"><h3 class="text-sm font-semibold text-gray-900">Recent administrative activity</h3><a href="{{ route('admin.audit-logs.index') }}" wire:navigate class="text-xs font-medium text-brand-600">View all</a></div>
            <table class="w-full text-xs">
                <tbody>
                    @forelse ($this->activity as $log)
                        <tr class="border-t border-gray-100 first:border-0" wire:key="a-{{ $log->id }}">
                            <td class="py-2"><x-ui.badge>{{ $log->action }}</x-ui.badge></td>
                            <td class="py-2 text-gray-600">{{ $log->library?->name ?? 'Platform' }}</td>
                            <td class="py-2 text-gray-500">{{ $log->actor?->name ?? 'System' }}</td>
                            <td class="py-2 text-right text-gray-400">{{ $log->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-6 text-center text-gray-400">No activity yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</div>
