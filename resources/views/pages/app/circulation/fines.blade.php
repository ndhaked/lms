<?php

use App\Actions\Circulation\SettleFine;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Fine;
use App\Models\Payment;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithPagination;

    #[Url]
    public string $status = 'pending';

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $acting = null;
    public string $action = '';            // collect | waive
    public string $method = 'cash';
    public string $reference = '';
    public string $waiveAmount = '';
    public string $reason = '';

    public function mount(): void
    {
        $this->authorizePermission('fines.view');
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['status', 'search'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function totals(): array
    {
        $pending = Fine::pending()->get();

        return [
            'pending' => (float) $pending->sum(fn ($f) => (float) $f->outstanding()),
            'count' => $pending->count(),
            'today' => (float) Fine::where('status', Fine::PAID)->whereDate('paid_on', today())->sum('paid_amount'),
            'month' => (float) Fine::where('status', Fine::PAID)->whereBetween('paid_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('paid_amount'),
        ];
    }

    #[Computed]
    public function rows()
    {
        return Fine::with(['student', 'loanItem.book'])
            ->when(in_array($this->status, ['pending', 'paid', 'waived'], true), fn ($q) => $q->where('status', $this->status))
            ->when(trim($this->search) !== '', fn ($q) => $q->whereHas('student', fn ($s) => $s->where('name', 'like', '%'.trim($this->search).'%')->orWhere('student_code', 'like', '%'.trim($this->search).'%')->orWhere('phone', 'like', '%'.trim($this->search).'%')))
            ->latest('id')->paginate(20);
    }

    #[Computed]
    public function current(): ?Fine
    {
        return $this->acting ? Fine::with('student')->find($this->acting) : null;
    }

    public function start(int $id, string $action): void
    {
        $this->authorizePermission($action === 'waive' ? 'fines.waive' : 'fines.collect');
        abort_unless(in_array($action, ['collect', 'waive'], true), 422);
        Fine::findOrFail($id);   // tenant-scoped

        $this->acting = $id;
        $this->action = $action;
        $this->reset('reference', 'waiveAmount', 'reason');
        $this->method = 'cash';
        $this->resetErrorBag();
    }

    public function confirm(): void
    {
        $fine = Fine::findOrFail($this->acting);
        $settle = app(SettleFine::class);

        try {
            if ($this->action === 'collect') {
                $this->authorizePermission('fines.collect');
                $this->validate(['method' => ['required', 'in:'.implode(',', array_keys(Payment::METHODS))], 'reference' => ['nullable', 'string', 'max:100']]);
                $fine = $settle->collect($fine, $this->method, $this->reference);
                $message = "Collected. Receipt {$fine->receipt_no}.";
            } else {
                $this->authorizePermission('fines.waive');
                $this->validate(['reason' => ['required', 'string', 'max:500'], 'waiveAmount' => ['nullable', 'numeric', 'gt:0']], ['reason.required' => 'Say why the fine is waived.'], ['waiveAmount' => 'amount']);
                $settle->waive($fine, $this->reason, $this->waiveAmount ?: null);
                $message = 'Waived.';
            }
        } catch (BusinessRuleException $e) {
            $this->addError($this->action === 'collect' ? 'reference' : 'reason', $e->getMessage());

            return;
        }

        $this->closeModal();
        unset($this->rows, $this->totals);
        $this->dispatch('notify', message: $message);
    }

    public function closeModal(): void
    {
        $this->acting = null;
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Fines" subtitle="Overdue, lost and damaged book fines" /></x-slot:header>

    @php $slug = \App\Support\Tenancy::slug(); $t = $this->totals; @endphp

    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat label="Unpaid fines" :value="'₹'.number_format($t['pending'], 2)" icon="alert" tone="red" :hint="$t['count'].' open'" />
        <x-ui.stat label="Collected today" :value="'₹'.number_format($t['today'], 2)" icon="cash" tone="green" hint="Fine receipts" />
        <x-ui.stat label="Collected this month" :value="'₹'.number_format($t['month'], 2)" icon="receipt" tone="blue" hint="Fine receipts" />
    </div>

    <div class="flex flex-wrap gap-3">
        <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="pending">Unpaid</option><option value="paid">Paid</option><option value="waived">Waived</option><option value="all">All</option>
        </select>
        <div class="relative min-w-64 flex-1">
            <x-lm.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Member name, ID or phone…" class="w-full rounded-lg border-gray-300 py-2 pl-9 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left text-xs text-gray-500">
                <th class="px-4 py-3 font-medium">Member</th><th class="px-4 py-3 font-medium">Fine</th><th class="px-4 py-3 text-right font-medium">Amount</th><th class="px-4 py-3 text-right font-medium">Left to pay</th>
                <th class="px-4 py-3 font-medium">Date</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th>
            </tr></thead>
            <tbody>
                @forelse ($this->rows as $f)
                    <tr class="border-t border-gray-100" wire:key="f-{{ $f->id }}">
                        <td class="px-4 py-3"><p class="font-medium text-gray-900">{{ $f->student->name }}</p><p class="text-xs text-gray-500">{{ $f->student->student_code }}</p></td>
                        <td class="px-4 py-3"><p class="text-gray-900">{{ \App\Models\Fine::TYPES[$f->type] ?? $f->type }}</p><p class="max-w-56 truncate text-xs text-gray-500">{{ $f->note }}</p></td>
                        <td class="px-4 py-3 text-right">₹{{ number_format((float) $f->amount, 2) }}@if ((float) $f->waived_amount > 0)<p class="text-xs text-gray-400">₹{{ number_format((float) $f->waived_amount, 2) }} waived</p>@endif</td>
                        <td class="px-4 py-3 text-right font-semibold {{ $f->status === 'pending' ? 'text-red-600' : 'text-gray-400' }}">₹{{ number_format((float) $f->outstanding(), 2) }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ ($f->paid_on ?? $f->created_at)->format('d M Y') }}</td>
                        <td class="px-4 py-3"><x-ui.badge :color="match ($f->status) { 'paid' => 'green', 'waived' => 'gray', default => 'amber' }">{{ $f->status === 'pending' ? 'Unpaid' : ucfirst($f->status) }}</x-ui.badge>@if ($f->receipt_no)<p class="mt-0.5 text-xs text-gray-400">{{ $f->receipt_no }}</p>@endif</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            @if ($f->status === 'pending')
                                @if ($this->can('fines.collect')) <button wire:click="start({{ $f->id }}, 'collect')" class="font-medium text-brand-600 hover:text-brand-800">Collect</button> @endif
                                @if ($this->can('fines.waive')) <button wire:click="start({{ $f->id }}, 'waive')" class="ml-3 font-medium text-gray-600 hover:text-gray-900">Waive</button> @endif
                            @elseif ($f->status === 'paid')
                                <a href="{{ route('app.circulation.receipt.fine', ['library' => $slug, 'fine' => $f->id]) }}" target="_blank" class="font-medium text-gray-600 hover:text-gray-900">Receipt</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-400">No fines here.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->rows->links() }}

    @if ($this->current)
        @php $c = $this->current; @endphp
        <x-ui.modal :title="$action === 'collect' ? 'Collect fine' : 'Waive fine'" max="max-w-md" wire:key="fine-modal">
            <form wire:submit="confirm" class="space-y-4">
                <p class="text-sm text-gray-700"><strong>{{ $c->student->name }}</strong> · {{ \App\Models\Fine::TYPES[$c->type] ?? $c->type }} · left to pay <strong>₹{{ number_format((float) $c->outstanding(), 2) }}</strong></p>
                @if ($action === 'collect')
                    <x-ui.select name="method" label="Method" required wire:model="method">@foreach (\App\Models\Payment::METHODS as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach</x-ui.select>
                    <x-ui.input name="reference" label="Reference (optional)" wire:model="reference" />
                @else
                    <x-ui.input name="waiveAmount" label="Amount to waive (₹)" type="number" step="0.01" min="0" wire:model="waiveAmount" hint="Leave empty to waive everything that is left." />
                    <x-ui.textarea name="reason" label="Reason (kept in the history)" required wire:model="reason" rows="3" />
                @endif
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">{{ $action === 'collect' ? 'Collect' : 'Waive' }}</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
