<?php

use App\Support\LibraryInsights;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    public function mount(): void
    {
        $can = fn ($p) => auth()->user()->can($p);
        abort_unless($can('students.view') || $can('memberships.view') || $can('payments.view') || $can('attendance.view'), 403);
    }

    #[Computed]
    public function data(): array
    {
        return LibraryInsights::all();
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Insights" subtitle="Library health at a glance" /></x-slot:header>

    @php
        $i = $this->data;
        $m = $i['members']; $mo = $i['money']; $at = $i['attendance']; $se = $i['seats'];
        $can = fn ($p) => auth()->user()->can($p);
        $slug = Tenancy::slug();
        $lib = Tenancy::library();
        $showMembers = $can('students.view') || $can('memberships.view');
        $showMoney = $can('payments.view');
        $showAttendance = $can('attendance.view');
        $s = fn ($v) => LibraryInsights::short($v);
        $pctBox = fn ($label, $value, $pct) => compact('label', 'value', 'pct');
        $weekMax = max(1, collect($at['week'])->max('value'));
    @endphp

    <section class="flex flex-wrap items-start justify-between gap-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <div>
            <p class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-brand-700">✦ Dashboard</p>
            <h2 class="mt-3 text-2xl font-extrabold text-gray-900 sm:text-3xl">Welcome to {{ $lib?->name }}@if ($lib?->code) <span class="text-gray-400">({{ $lib->code }})</span>@endif</h2>
            <p class="mt-1 text-sm text-gray-500">Live operations, revenue signals, member movement and expiring plans in one place.</p>
        </div>
        <a href="{{ route('app.dashboard', ['library' => $slug]) }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-4 py-2.5 text-sm font-bold text-brand-700 hover:bg-brand-100">← Dashboard</a>
    </section>

    <section class="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <div>
            <p class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-brand-700">✦ Home insights</p>
            <h3 class="mt-2 text-2xl font-extrabold text-gray-900">Library health at a glance</h3>
            <p class="text-sm text-gray-500">Member, money, attendance and renewal signals from your own library.</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @if ($showMembers)
                <div class="flex items-center gap-3 rounded-2xl bg-gradient-to-br from-blue-50 to-white p-4 ring-1 ring-blue-100"><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-600 text-white"><x-lm.icon name="users" class="h-5 w-5" /></span>
                    <div><p class="text-xs font-semibold text-gray-600">Live rate</p><p class="text-2xl font-extrabold text-blue-700">{{ $m['live_pct'] }}%</p><p class="text-[11px] text-gray-500">{{ $m['live'] }}/{{ $m['total'] }} members</p></div></div>
            @endif
            @if ($showMoney)
                <div class="flex items-center gap-3 rounded-2xl bg-gradient-to-br from-emerald-50 to-white p-4 ring-1 ring-emerald-100"><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-600 text-white"><x-lm.icon name="chart" class="h-5 w-5" /></span>
                    <div><p class="text-xs font-semibold text-gray-600">Money trend</p><p class="text-2xl font-extrabold {{ $mo['change'] >= 0 ? 'text-emerald-700' : 'text-red-600' }}">{{ $mo['change'] > 0 ? '+' : '' }}{{ $mo['change'] }}%</p><p class="text-[11px] text-gray-500">vs last month</p></div></div>
            @endif
            @if ($showAttendance)
                <div class="flex items-center gap-3 rounded-2xl bg-gradient-to-br from-rose-50 to-white p-4 ring-1 ring-rose-100"><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-rose-500 text-white"><x-lm.icon name="clock" class="h-5 w-5" /></span>
                    <div><p class="text-xs font-semibold text-gray-600">Attendance</p><p class="text-2xl font-extrabold text-rose-600">{{ $at['pct'] }}%</p><p class="text-[11px] text-gray-500">{{ $at['today'] }} check-ins today</p></div></div>
            @endif
            @if ($showMembers)
                <div class="flex items-center gap-3 rounded-2xl bg-gradient-to-br from-amber-50 to-white p-4 ring-1 ring-amber-100"><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-500 text-white"><x-lm.icon name="alert" class="h-5 w-5" /></span>
                    <div><p class="text-xs font-semibold text-gray-600">Expiring soon</p><p class="text-2xl font-extrabold text-amber-600">{{ $m['expiring']['window'] }}</p><p class="text-[11px] text-gray-500">within {{ $i['window'] }} days</p></div></div>
            @endif
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        @if ($showMembers)
            <section class="space-y-4 rounded-2xl border border-gray-200 border-t-4 border-t-emerald-500 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-white"><x-lm.icon name="users" class="h-5 w-5" /></span>
                    <div><h3 class="text-lg font-extrabold text-gray-900">Member health</h3><p class="text-xs text-gray-500">Live, expired and expiring members</p></div></div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-sky-600 to-blue-500 p-4 text-white shadow-md"><span class="pointer-events-none absolute -bottom-6 -right-5 h-16 w-16 rounded-full bg-white/15"></span><p class="text-3xl font-extrabold">{{ $m['live'] }}</p><p class="text-xs opacity-90">Live members</p></div>
                    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-red-600 to-rose-500 p-4 text-white shadow-md"><span class="pointer-events-none absolute -bottom-6 -right-5 h-16 w-16 rounded-full bg-white/15"></span><p class="text-3xl font-extrabold">{{ $m['expired'] }}</p><p class="text-xs opacity-90">Expired</p></div>
                </div>
                <p class="flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-900"><span>⚡</span><span>{{ LibraryInsights::memberSentence($i) }}</span></p>
                <div class="grid grid-cols-3 gap-2 text-xs">
                    @foreach ([['Live', $m['live'], $m['live_pct']], ['Expired', $m['expired'], $m['expired_pct']], ['Expiring', $m['expiring']['window'], $m['live'] > 0 ? (int) round($m['expiring']['window'] / $m['live'] * 100) : 0]] as [$l, $v, $p])
                        <div class="rounded-xl bg-gradient-to-br {{ ['Live' => 'from-emerald-600 to-teal-500', 'Expired' => 'from-red-600 to-rose-500'][$l] ?? 'from-amber-500 to-orange-400' }} p-3 text-white shadow-sm"><p class="opacity-90">{{ $l }}</p><p class="mt-0.5 text-sm font-extrabold">{{ $v }} <span class="font-semibold opacity-80">{{ $p }}%</span></p></div>
                    @endforeach
                </div>
                <div class="p-2">
                    <x-lm.donut :segments="[['label' => 'Live', 'value' => max(0, $m['live'] - $m['expiring']['window']), 'color' => '#1d4ed8'], ['label' => 'Expiring', 'value' => $m['expiring']['window'], 'color' => '#f59e0b'], ['label' => 'Expired', 'value' => $m['expired'], 'color' => '#ef4444']]" :center="$m['live_pct'].'%'" sub="live" :size="170" />
                </div>
            </section>
        @endif

        @if ($showMoney)
            <section class="space-y-4 rounded-2xl border border-gray-200 border-t-4 border-t-amber-500 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-600 text-white"><x-lm.icon name="chart" class="h-5 w-5" /></span>
                    <div><h3 class="text-lg font-extrabold text-gray-900">Money flow</h3><p class="text-xs text-gray-500">Last month, this month and today</p></div></div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-cyan-600 to-teal-500 p-4 text-white shadow-md"><span class="pointer-events-none absolute -bottom-6 -right-5 h-16 w-16 rounded-full bg-white/15"></span><p class="text-3xl font-extrabold">{{ $s($mo['last_month']) }}</p><p class="text-xs opacity-90">Last month collection</p></div>
                    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-600 to-teal-500 p-4 text-white shadow-md"><span class="pointer-events-none absolute -bottom-6 -right-5 h-16 w-16 rounded-full bg-white/15"></span><p class="text-3xl font-extrabold">{{ $s($mo['month']) }}</p><p class="text-xs opacity-90">This month</p></div>
                </div>
                <p class="flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-900"><span>⚡</span><span>{{ LibraryInsights::moneySentence($i) }}</span></p>
                <div class="grid grid-cols-3 gap-2 text-xs">
                    @foreach ([['Last month', $mo['last_month'], null], ['This month', $mo['month'], null], ['Today', $mo['today'], $mo['today_pct']]] as [$l, $v, $p])
                        <div class="rounded-xl bg-gradient-to-br {{ ['Last month' => 'from-cyan-600 to-teal-500', 'This month' => 'from-emerald-600 to-teal-500'][$l] ?? 'from-amber-500 to-orange-400' }} p-3 text-white shadow-sm"><p class="opacity-90">{{ $l }}</p><p class="mt-0.5 text-sm font-extrabold">{{ $s($v) }} @if ($p !== null)<span class="font-semibold opacity-80">{{ $p }}%</span>@endif</p></div>
                    @endforeach
                </div>
                @php $bars = [['Last month', $mo['last_month'], '#ef4444'], ['This month', $mo['month'], '#10b981'], ['Today', $mo['today'], '#f59e0b'], ['Dues', $mo['due'], '#8b5cf6']]; $barMax = max(1, max(array_column($bars, 1))); @endphp
                <div class="p-2">
                    <div class="flex h-40 items-end justify-around gap-4">
                        @foreach ($bars as [$l, $v, $c])
                            <div class="flex h-full flex-1 flex-col items-center justify-end gap-1"><span class="text-[11px] font-semibold text-gray-600">{{ $s($v) }}</span><div class="w-full max-w-[60px] rounded-t-md" style="height: {{ max(3, $v / $barMax * 100) }}%; background: {{ $c }}"></div><span class="text-[11px] text-gray-500">{{ $l }}</span></div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
    </div>

    @if ($showAttendance || $can('seats.view'))
        <div class="grid gap-5 lg:grid-cols-2">
            @if ($showAttendance)
                <section class="space-y-3 rounded-2xl border border-gray-200 border-t-4 border-t-rose-500 bg-white p-5 shadow-sm">
                    <div><h3 class="text-lg font-extrabold text-gray-900">Attendance this week</h3><p class="text-xs text-gray-500">Different students checked in each day</p></div>
                    <div class="flex h-40 items-end justify-around gap-3 p-2">
                        @foreach ($at['week'] as $d)
                            <div class="flex h-full flex-1 flex-col items-center justify-end gap-1"><span class="text-[11px] font-semibold text-gray-600">{{ $d['value'] }}</span><div class="w-full max-w-[36px] rounded-t-md bg-rose-400" style="height: {{ max(3, $d['value'] / $weekMax * 100) }}%"></div><span class="text-[11px] text-gray-500">{{ $d['label'] }}</span></div>
                        @endforeach
                    </div>
                    <p class="text-xs text-gray-500">{{ $at['inside'] }} inside right now.</p>
                </section>
            @endif
            @if ($can('seats.view'))
                <section class="space-y-3 rounded-2xl border border-gray-200 border-t-4 border-t-violet-500 bg-white p-5 shadow-sm">
                    <div><h3 class="text-lg font-extrabold text-gray-900">Seat occupancy</h3><p class="text-xs text-gray-500">Seats in use right now</p></div>
                    <div class="p-2"><x-lm.donut :segments="[['label' => 'Occupied', 'value' => $se['used'], 'color' => '#8b5cf6'], ['label' => 'Free', 'value' => max(0, $se['total'] - $se['used']), 'color' => '#d1d5db']]" :center="$se['pct'].'%'" :sub="$se['used'].' of '.$se['total']" :size="170" /></div>
                </section>
            @endif
        </div>
    @endif
</div>
