<?php

use App\Actions\Billing\ReviewCreditPurchase;
use App\Exceptions\BusinessRuleException;
use App\Models\CreditPurchase;
use App\Support\BillingSettings;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'pending';

    public ?int $reviewing = null;
    public string $decision = '';
    public string $note = '';

    public array $rates = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('payments.approve'), 403);

        foreach (['sms', 'whatsapp'] as $ch) {
            $r = (array) BillingSettings::get('credit_rates')[$ch];
            $this->rates[$ch] = ['rate' => (string) $r['rate'], 'mrp' => (string) $r['mrp'], 'min' => (string) $r['min'], 'packs' => implode(', ', $r['packs'])];
        }
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function purchases()
    {
        return CreditPurchase::with(['library', 'user'])->when($this->status !== '', fn ($q) => $q->where('status', $this->status))->latest('id')->paginate(15);
    }

    #[Computed]
    public function item(): ?CreditPurchase
    {
        return $this->reviewing ? CreditPurchase::with(['library', 'user', 'reviewer', 'proofs'])->find($this->reviewing) : null;
    }

    public function review(int $id): void
    {
        $this->reviewing = $id;
        $this->reset(['decision', 'note']);
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->reviewing = null;
    }

    public function decide(): void
    {
        $p = $this->item;
        abort_unless($p, 404);
        $this->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->decision === 'approve'
                ? app(ReviewCreditPurchase::class)->approve($p, $this->note ?: null)
                : app(ReviewCreditPurchase::class)->reject($p, $this->note);
        } catch (BusinessRuleException $e) {
            $this->addError('note', $e->getMessage());

            return;
        }

        $this->reviewing = null;
        unset($this->purchases);
        $this->dispatch('notify', message: $this->decision === 'approve' ? 'Approved. Credits added.' : 'Rejected.');
    }

    public function saveRates(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('settings.manage'), 403);
        $this->validate([
            'rates.*.rate' => ['required', 'numeric', 'gt:0'], 'rates.*.mrp' => ['required', 'numeric', 'gt:0'],
            'rates.*.min' => ['required', 'integer', 'min:1'], 'rates.*.packs' => ['required', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
        ], ['rates.*.packs.regex' => 'Enter pack sizes as numbers separated by commas, for example 1000, 2000, 5000.'], ['rates.*.mrp' => 'list price', 'rates.*.min' => 'minimum', 'rates.*.packs' => 'pack sizes']);

        $clean = [];
        foreach ($this->rates as $ch => $r) {
            $packs = collect(explode(',', $r['packs']))->map(fn ($n) => (int) trim($n))->filter()->unique()->sort()->values()->all();
            $clean[$ch] = ['rate' => number_format((float) $r['rate'], 2, '.', ''), 'mrp' => number_format((float) $r['mrp'], 2, '.', ''), 'min' => (int) $r['min'], 'packs' => $packs];
        }
        BillingSettings::set(['credit_rates' => $clean]);
        $this->dispatch('notify', message: 'Rates saved.');
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="SMS & WhatsApp credits" subtitle="Approve credit purchases and set the rates" /></x-slot:header>

    @php $color = fn ($st) => match ($st) { 'approved' => 'green', 'pending' => 'blue', default => 'red' }; @endphp

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 p-4">
            <h3 class="text-sm font-semibold text-gray-900">Purchases</h3>
            <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="">All</option>
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Reference</th><th class="px-4 py-3 font-medium">Library</th><th class="px-4 py-3 font-medium">Channel</th><th class="px-4 py-3 text-right font-medium">Credits</th><th class="px-4 py-3 text-right font-medium">Amount</th><th class="px-4 py-3 font-medium">Date</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th></tr></thead>
                <tbody>
                    @forelse ($this->purchases as $p)
                        <tr class="border-t border-gray-100" wire:key="cr-{{ $p->id }}">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $p->reference }}</td>
                            <td class="px-4 py-3">{{ $p->library->name }}</td>
                            <td class="px-4 py-3">{{ \App\Models\CreditPurchase::CHANNELS[$p->channel] }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($p->credits) }}</td>
                            <td class="px-4 py-3 text-right font-semibold">₹{{ number_format((float) $p->amount, 2) }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $p->created_at->format('d M Y') }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$color($p->status)">{{ \App\Models\CreditPurchase::STATUSES[$p->status] }}</x-ui.badge></td>
                            <td class="px-4 py-3 text-right"><button wire:click="review({{ $p->id }})" class="font-medium text-brand-600 hover:text-brand-800">{{ $p->status === 'pending' ? 'Review' : 'View' }}</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->purchases->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->purchases->links() }}</div> @endif
    </div>

    @if (auth()->user()->hasPlatformPermission('settings.manage'))
        <form wire:submit="saveRates" class="space-y-4 rounded-xl border border-gray-200 bg-white p-5">
            <div><h3 class="text-sm font-semibold text-gray-900">Rates and packs</h3><p class="text-xs text-gray-500">Shown to library owners on the SMS and WhatsApp tabs of the plans page.</p></div>
            <div class="grid gap-6 lg:grid-cols-2">
                @foreach (['sms' => 'SMS', 'whatsapp' => 'WhatsApp'] as $ch => $label)
                    <fieldset class="space-y-3" wire:key="rate-{{ $ch }}">
                        <legend class="mb-2 text-sm font-semibold text-gray-800">{{ $label }}</legend>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <x-ui.input name="rates.{{ $ch }}.rate" label="Rate per credit (₹)" type="number" step="0.01" wire:model="rates.{{ $ch }}.rate" />
                            <x-ui.input name="rates.{{ $ch }}.mrp" label="List price (₹)" type="number" step="0.01" wire:model="rates.{{ $ch }}.mrp" />
                            <x-ui.input name="rates.{{ $ch }}.min" label="Minimum credits" type="number" wire:model="rates.{{ $ch }}.min" />
                        </div>
                        <x-ui.input name="rates.{{ $ch }}.packs" label="Pack sizes (comma separated)" wire:model="rates.{{ $ch }}.packs" />
                    </fieldset>
                @endforeach
            </div>
            <div class="flex justify-end"><x-ui.button type="submit" wire:target="saveRates">Save rates</x-ui.button></div>
        </form>
    @endif

    @if ($this->item)
        @php $p = $this->item; @endphp
        <x-ui.modal title="Credit purchase {{ $p->reference }}" close="close()" max="max-w-lg" wire:key="credit-review">
            <div class="space-y-4 text-sm">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    @foreach (['Library' => $p->library->name, 'Channel' => \App\Models\CreditPurchase::CHANNELS[$p->channel], 'Credits' => number_format($p->credits), 'Amount' => '₹'.number_format((float) $p->amount, 2),
                        'Method' => \App\Models\SubscriptionPayment::METHODS[$p->method] ?? $p->method, 'Paid on' => $p->paid_on->format('d M Y'), 'Submitted by' => $p->user?->name ?? '—'] as $l => $v)
                        <div><dt class="text-xs font-medium text-gray-500">{{ $l }}</dt><dd class="mt-0.5 text-gray-900">{{ $v }}</dd></div>
                    @endforeach
                </dl>
                @if ($p->note)<div class="rounded-lg bg-gray-50 p-3"><p class="text-xs font-medium text-gray-500">Note from the library</p><p class="mt-1 whitespace-pre-line text-gray-800">{{ $p->note }}</p></div>@endif
                <div>
                    <p class="mb-1 text-xs font-medium text-gray-500">Proof of payment</p>
                    @forelse ($p->proofs as $proof)
                        <a href="{{ route('admin.credit-proofs.show', $proof) }}" target="_blank" class="mr-2 inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-brand-700 hover:bg-brand-50">{{ $proof->original_name }} <span class="text-gray-400">({{ round($proof->size / 1024) }} KB)</span></a>
                    @empty
                        <span class="text-gray-400">No proof attached.</span>
                    @endforelse
                </div>

                @if ($p->status === 'pending')
                    <form wire:submit="decide" class="space-y-3 border-t border-gray-100 pt-4">
                        <div class="flex gap-2">
                            @foreach (['approve' => 'Approve', 'reject' => 'Reject'] as $k => $l)
                                <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 {{ $decision === $k ? 'border-brand-400 bg-brand-50' : 'border-gray-200' }}"><input type="radio" wire:model.live="decision" value="{{ $k }}" class="text-brand-600 focus:ring-brand-500"> {{ $l }}</label>
                            @endforeach
                        </div>
                        @if ($decision)
                            <x-ui.textarea name="note" :label="$decision === 'approve' ? 'Note (optional)' : 'Reason for rejecting'" wire:model="note" />
                            <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="close">Cancel</x-ui.button><x-ui.button type="submit" :variant="$decision === 'reject' ? 'danger' : 'primary'" wire:target="decide">{{ $decision === 'approve' ? 'Approve and add credits' : 'Reject' }}</x-ui.button></div>
                        @endif
                    </form>
                @else
                    @if ($p->review_note)<div class="rounded-lg bg-gray-50 p-3 text-xs text-gray-700"><span class="font-medium">{{ \App\Models\CreditPurchase::STATUSES[$p->status] }}</span>{{ $p->reviewer ? ' by '.$p->reviewer->name : '' }}: {{ $p->review_note }}</div>@endif
                    <div class="flex justify-end"><x-ui.button variant="secondary" wire:click="close">Close</x-ui.button></div>
                @endif
            </div>
        </x-ui.modal>
    @endif
</div>
