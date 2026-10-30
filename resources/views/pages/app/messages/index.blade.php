<?php

use App\Models\CreditPurchase;
use App\Models\MessageLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $channel = 'sms';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('billing.view'), 403);
        $this->channel = isset(CreditPurchase::CHANNELS[$this->channel]) ? $this->channel : 'sms';
    }

    public function updatedStatus(): void
    {
        $this->status = in_array($this->status, ['sent', 'failed'], true) ? $this->status : '';
        $this->resetPage();
    }

    /** Sent and failed totals for the open channel. */
    #[Computed]
    public function totals(): array
    {
        $q = MessageLog::where('channel', $this->channel);

        return ['sent' => (clone $q)->where('status', 'sent')->count(), 'failed' => (clone $q)->where('status', 'failed')->count()];
    }

    public function updatedChannel(): void
    {
        $this->channel = isset(CreditPurchase::CHANNELS[$this->channel]) ? $this->channel : 'sms';
        $this->resetPage();
    }

    #[Computed]
    public function messages()
    {
        return MessageLog::where('channel', $this->channel)->when(in_array($this->status, ['sent', 'failed'], true), fn ($q) => $q->where('status', $this->status))->latest('id')->paginate(15);
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title :title="CreditPurchase::CHANNELS[$channel].' history'" subtitle="Every message sent from your library" /></x-slot:header>

    @php $label = CreditPurchase::CHANNELS[$channel]; $slug = \App\Support\Tenancy::slug(); @endphp

    <section class="flex flex-wrap items-start justify-between gap-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <div>
            <p class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-brand-700"><x-lm.icon name="chat" class="h-3.5 w-3.5" /> Communication</p>
            <h2 class="mt-3 text-3xl font-extrabold text-gray-900">{{ $label }} History</h2>
            <p class="mt-1 text-sm text-gray-500">Every {{ $label }} message sent to students and parents, with the credits it used.</p>
        </div>
        <a href="{{ route('app.subscription.plans', ['library' => $slug, 'tab' => $channel]) }}" wire:navigate class="rounded-xl border border-brand-200 bg-brand-50 px-4 py-2.5 text-sm font-bold text-brand-700 hover:bg-brand-100">Buy {{ $label }} credits</a>
    </section>

    <div class="rounded-2xl border border-gray-200 bg-white p-3 shadow-sm">
        <div class="flex rounded-2xl border border-gray-200 bg-gray-50 p-1">
            @foreach (\App\Models\CreditPurchase::CHANNELS as $key => $l)
                <button type="button" wire:click="$set('channel', '{{ $key }}')" wire:key="mt-{{ $key }}" class="flex-1 rounded-xl px-6 py-3 text-sm font-bold {{ $channel === $key ? 'bg-gradient-to-r from-teal-800 to-slate-900 text-white shadow' : 'text-gray-600 hover:text-gray-900' }}">{{ $l }}</button>
            @endforeach
        </div>
    </div>

    @php $tot = $this->totals; @endphp
    <div class="flex flex-wrap items-center gap-3">
        <span class="rounded-full bg-emerald-50 px-4 py-1.5 text-sm font-bold text-emerald-700">✓ Sent {{ number_format($tot['sent']) }}</span>
        <span class="rounded-full px-4 py-1.5 text-sm font-bold {{ $tot['failed'] ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-500' }}">✕ Not sent {{ number_format($tot['failed']) }}</span>
        <select wire:model.live="status" class="ml-auto rounded-lg border-gray-300 text-sm"><option value="">Sent and not sent</option><option value="sent">Sent only</option><option value="failed">Not sent only</option></select>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b-2 border-brand-100 bg-gray-50 text-left text-xs font-bold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">#</th><th class="px-4 py-3">Date</th><th class="px-4 py-3">Mobile</th><th class="px-4 py-3">Count</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Message</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->messages as $m)
                        <tr class="border-t border-gray-100 align-top" wire:key="msg-{{ $m->id }}">
                            <td class="px-4 py-3 text-gray-500">{{ $this->messages->firstItem() + $loop->index }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-gray-800"><x-lm.icon name="clock" class="mr-1 inline h-4 w-4 text-brand-600" />{{ $m->created_at->format('Y-m-d H:i:s') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-gray-800">{{ $m->mobile }}</td>
                            <td class="px-4 py-3"><span class="inline-flex h-7 min-w-7 items-center justify-center rounded-full bg-brand-100 px-2 text-xs font-bold text-brand-800">{{ $m->count }}</span></td>
                            <td class="px-4 py-3">@if ($m->status === 'sent')<span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">✓ Sent</span>@else<span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-1 text-xs font-bold text-red-700">✕ Not sent</span>@endif</td>
                            <td class="px-4 py-3 text-gray-700">{{ $m->message }}@if ($m->status === 'failed')<span class="mt-1 block text-xs font-semibold text-red-600">Reason: {{ $m->error ?: 'Unknown' }}. Credits were not used.</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">No {{ $label }} messages yet. Messages sent to students and parents will be listed here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($this->messages->hasPages())
        <div class="flex items-center justify-between gap-3 text-sm">
            <button type="button" wire:click="previousPage" @disabled($this->messages->onFirstPage()) class="rounded-full border border-gray-200 bg-white px-5 py-2 font-semibold text-gray-600 disabled:opacity-40">‹ Previous</button>
            <span class="rounded-full border border-gray-200 bg-white px-5 py-2 font-extrabold text-gray-900">Page {{ $this->messages->currentPage() }}</span>
            <button type="button" wire:click="nextPage" @disabled(! $this->messages->hasMorePages()) class="rounded-full border border-gray-200 bg-white px-5 py-2 font-semibold text-gray-600 disabled:opacity-40">Next ›</button>
        </div>
    @endif
</div>
