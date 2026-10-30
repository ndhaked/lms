<?php

use App\Models\OnlinePayment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Every Razorpay order libraries started, and whether it was paid and granted. For matching against the Razorpay dashboard. */
new #[Layout('layouts::panel')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('payments.approve'), 403);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['status', 'search'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function payments()
    {
        return OnlinePayment::with(['library:id,name,code', 'plan:id,name'])
            ->when(isset(OnlinePayment::STATUSES[$this->status]), fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('razorpay_order_id', 'like', "%{$this->search}%")->orWhere('razorpay_payment_id', 'like', "%{$this->search}%")
                ->orWhereHas('library', fn ($l) => $l->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%"))))
            ->latest('id')->paginate(20);
    }

    #[Computed]
    public function totals(): array
    {
        return ['paid' => (float) OnlinePayment::where('status', 'paid')->sum('amount'), 'open' => OnlinePayment::where('status', 'created')->where('created_at', '>=', now()->subDay())->count(), 'failed' => OnlinePayment::where('status', 'failed')->count()];
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Online payments" subtitle="Razorpay orders from libraries" /></x-slot:header>

    @php $t = $this->totals; $color = fn ($s) => match ($s) { 'paid' => 'green', 'failed' => 'red', default => 'blue' }; @endphp

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-4"><p class="text-xs font-medium text-gray-500">Received online</p><p class="text-2xl font-extrabold text-emerald-600">₹{{ number_format($t['paid'], 2) }}</p></div>
        <div class="rounded-xl border border-gray-200 bg-white p-4"><p class="text-xs font-medium text-gray-500">Waiting for payment (24 hours)</p><p class="text-2xl font-extrabold text-gray-900">{{ $t['open'] }}</p></div>
        <div class="rounded-xl border border-gray-200 bg-white p-4"><p class="text-xs font-medium text-gray-500">Needs a look (failed or mismatched)</p><p class="text-2xl font-extrabold {{ $t['failed'] ? 'text-red-600' : 'text-gray-900' }}">{{ $t['failed'] }}</p></div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search order id, payment id or library…" class="w-72 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm"><option value="">All</option>@foreach (\App\Models\OnlinePayment::STATUSES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Date</th><th class="px-4 py-3 font-medium">Library</th><th class="px-4 py-3 font-medium">For</th><th class="px-4 py-3 text-right font-medium">Amount</th><th class="px-4 py-3 font-medium">Razorpay ids</th><th class="px-4 py-3 font-medium">Status</th></tr></thead>
                <tbody>
                    @forelse ($this->payments as $p)
                        <tr class="border-t border-gray-100 align-top" wire:key="op-{{ $p->id }}">
                            <td class="whitespace-nowrap px-4 py-3 text-gray-600">{{ $p->created_at->format('d M Y H:i') }}</td>
                            <td class="px-4 py-3">{{ $p->library?->name }}<div class="text-xs text-gray-400">{{ $p->library?->code }}</div></td>
                            <td class="px-4 py-3">{{ $p->describe() }}@if ($p->coins_used)<div class="text-xs text-gray-400">{{ $p->coins_used }} coins used</div>@endif</td>
                            <td class="px-4 py-3 text-right font-semibold">₹{{ number_format((float) $p->amount, 2) }}</td>
                            <td class="px-4 py-3 font-mono text-[11px] text-gray-600">{{ $p->razorpay_order_id }}<br>{{ $p->razorpay_payment_id ?? '—' }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$color($p->status)">{{ \App\Models\OnlinePayment::STATUSES[$p->status] }}</x-ui.badge>@if ($p->failure_reason)<div class="mt-1 max-w-[14rem] text-xs text-red-700">{{ $p->failure_reason }}</div>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">No online payments yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->payments->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->payments->links() }}</div> @endif
    </div>
</div>
