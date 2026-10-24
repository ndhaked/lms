<?php

use App\Actions\Billing\ApproveSubscriptionPayment;
use App\Actions\Billing\ReviewSubscriptionPayment;
use App\Exceptions\BusinessRuleException;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
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

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $reviewing = null;
    public string $decision = '';    // approve | reject | info
    public string $note = '';

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
        return SubscriptionPayment::query()
            ->with(['library', 'plan', 'subscription', 'proofs'])
            ->when($this->status === 'open', fn ($q) => $q->whereIn('status', ['pending', 'info_requested']))
            ->when(! in_array($this->status, ['', 'open'], true), fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', "%{$this->search}%")
                ->orWhereHas('library', fn ($l) => $l->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%"))))
            ->latest('id')->paginate(15);
    }

    #[Computed]
    public function item(): ?SubscriptionPayment
    {
        return $this->reviewing
            ? SubscriptionPayment::with(['library', 'plan', 'subscription.nextPlan', 'proofs', 'submitter', 'reviewer'])->find($this->reviewing)
            : null;
    }

    #[Computed]
    public function history()
    {
        return $this->item
            ? SubscriptionPayment::where('library_id', $this->item->library_id)->where('id', '!=', $this->item->id)->latest('id')->limit(8)->get()
            : collect();
    }

    /** What approving would do to the subscription, shown before the decision is made. */
    #[Computed]
    public function preview(): ?array
    {
        $p = $this->item;
        if (! $p || $p->status !== 'pending') {
            return null;
        }
        $s = $p->subscription;
        $covered = in_array($s->status, ['trial', 'active'], true) ? $s->coveredUntil() : null;
        $start = $covered && $covered->gte(today()) ? $covered->copy()->addDay() : today();

        return ['start' => $start, 'end' => Subscription::periodEnd($start, $p->billing_cycle), 'plan' => ($s->nextPlan ?? $p->plan ?? $s->plan)?->name, 'stays_suspended' => $s->status === 'suspended'];
    }

    public function review(int $id): void
    {
        $this->reviewing = SubscriptionPayment::findOrFail($id)->id;
        $this->reset(['decision', 'note']);
        $this->resetErrorBag();
    }

    public function updatedDecision(): void
    {
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

        if (in_array($this->decision, ['reject', 'info'], true)) {
            $this->validate(['note' => ['required', 'string', 'max:1000']], [], ['note' => $this->decision === 'reject' ? 'reason' : 'question']);
        }

        try {
            match ($this->decision) {
                'approve' => app(ApproveSubscriptionPayment::class)($p, $this->note ?: null),
                'reject' => app(ReviewSubscriptionPayment::class)->reject($p, $this->note),
                'info' => app(ReviewSubscriptionPayment::class)->requestInfo($p, $this->note),
                default => throw new BusinessRuleException('Choose what to do with this payment.'),
            };
        } catch (BusinessRuleException $e) {
            $this->addError('note', $e->getMessage());

            return;
        }

        $label = ['approve' => 'approved', 'reject' => 'rejected', 'info' => 'sent back for more information'][$this->decision];
        $this->reviewing = null;
        unset($this->payments);
        $this->dispatch('notify', message: "Payment {$label}.");
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Payment approvals" subtitle="Subscription payments reported by libraries" /></x-slot:header>

    @php
        $payColor = fn ($st) => match ($st) { 'approved' => 'green', 'pending' => 'blue', 'info_requested' => 'amber', 'rejected' => 'red', default => 'gray' };
    @endphp

    <div class="flex flex-wrap items-center gap-3">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Library or reference…" class="w-60 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="pending">Pending review</option><option value="info_requested">Waiting for information</option><option value="open">All open</option>
            <option value="approved">Approved</option><option value="rejected">Rejected</option><option value="">Everything</option>
        </select>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Reference</th><th class="px-4 py-3 font-medium">Library</th><th class="px-4 py-3 font-medium">Plan</th><th class="px-4 py-3 text-right font-medium">Amount</th><th class="px-4 py-3 font-medium">Method</th><th class="px-4 py-3 font-medium">Submitted</th><th class="px-4 py-3 font-medium">Proof</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->payments as $p)
                        <tr wire:key="ap-{{ $p->id }}">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $p->reference }}</td>
                            <td class="px-4 py-3">{{ $p->library->name }}<div class="text-xs text-gray-400">{{ $p->library->code }}</div></td>
                            <td class="px-4 py-3">{{ $p->plan?->name ?? $p->subscription->plan_name }}<div class="text-xs text-gray-400">{{ ucfirst($p->billing_cycle) }}</div></td>
                            <td class="px-4 py-3 text-right font-semibold">₹{{ number_format((float) $p->amount, 2) }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ \App\Models\SubscriptionPayment::METHODS[$p->method] ?? $p->method }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $p->created_at->format('d M Y') }}<div class="text-xs text-gray-400">paid {{ $p->paid_on->format('d M') }}</div></td>
                            <td class="px-4 py-3 text-gray-600">{{ $p->proofs->count() ? $p->proofs->count().' file(s)' : '—' }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$payColor($p->status)">{{ \App\Models\SubscriptionPayment::STATUSES[$p->status] }}</x-ui.badge></td>
                            <td class="px-4 py-3 text-right"><button wire:click="review({{ $p->id }})" class="font-medium text-brand-600 hover:text-brand-800">{{ $p->status === 'pending' ? 'Review' : 'View' }}</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->payments->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->payments->links() }}</div> @endif
    </div>

    @if ($this->item)
        @php $p = $this->item; $pv = $this->preview; @endphp
        <x-ui.modal title="Payment {{ $p->reference }}" close="close()" max="max-w-3xl" wire:key="review-modal">
            <div class="space-y-5 text-sm">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                    @foreach (['Library' => $p->library->name.' ('.$p->library->code.')', 'Plan' => ($p->plan?->name ?? $p->subscription->plan_name).' · '.ucfirst($p->billing_cycle),
                        'Amount' => '₹'.number_format((float) $p->amount, 2), 'Method' => \App\Models\SubscriptionPayment::METHODS[$p->method] ?? $p->method,
                        'Paid on' => $p->paid_on->format('d M Y'), 'Submitted by' => $p->submitter?->name ?? '—'] as $label => $value)
                        <div><dt class="text-xs font-medium text-gray-500">{{ $label }}</dt><dd class="mt-0.5 text-gray-900">{{ $value }}</dd></div>
                    @endforeach
                </dl>

                @if ($p->note) <div class="rounded-lg bg-gray-50 p-3"><p class="text-xs font-medium text-gray-500">Note from the library</p><p class="mt-1 whitespace-pre-line text-gray-800">{{ $p->note }}</p></div> @endif

                <div>
                    <p class="mb-1 text-xs font-medium text-gray-500">Proof of payment</p>
                    @forelse ($p->proofs as $proof)
                        <a href="{{ route('admin.subscription-proofs.show', $proof) }}" target="_blank" class="mr-2 inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-brand-700 hover:bg-brand-50">{{ $proof->original_name }} <span class="text-gray-400">({{ round($proof->size / 1024) }} KB)</span></a>
                    @empty
                        <span class="text-gray-400">No proof attached.</span>
                    @endforelse
                </div>

                @if ($pv)
                    <div class="rounded-lg border border-brand-200 bg-brand-50/60 p-3 text-xs text-brand-900">
                        Approving will cover <strong>{{ $pv['start']->format('d M Y') }} – {{ $pv['end']->format('d M Y') }}</strong> on the <strong>{{ $pv['plan'] }}</strong> plan and issue an invoice.
                        @if ($pv['stays_suspended']) <br>The subscription is suspended, so it stays suspended until you reactivate it. @endif
                    </div>
                @endif

                @if ($this->history->isNotEmpty())
                    <div>
                        <p class="mb-1 text-xs font-medium text-gray-500">Earlier payments from this library</p>
                        <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 text-xs">
                            @foreach ($this->history as $h)
                                <li class="flex items-center justify-between px-3 py-2" wire:key="h-{{ $h->id }}"><span>{{ $h->created_at->format('d M Y') }} · {{ $h->reference }} · ₹{{ number_format((float) $h->amount, 2) }}</span><x-ui.badge :color="$payColor($h->status)">{{ \App\Models\SubscriptionPayment::STATUSES[$h->status] }}</x-ui.badge></li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($p->review_note && $p->status !== 'pending')
                    <div class="rounded-lg bg-gray-50 p-3 text-xs text-gray-700"><span class="font-medium">{{ \App\Models\SubscriptionPayment::STATUSES[$p->status] }}</span>{{ $p->reviewer ? ' by '.$p->reviewer->name : '' }}: {{ $p->review_note }}</div>
                @endif

                @if ($p->status === 'pending')
                    <form wire:submit="decide" class="space-y-3 border-t border-gray-100 pt-4">
                        <div class="flex flex-wrap gap-2">
                            @foreach (['approve' => 'Approve', 'info' => 'Ask for more information', 'reject' => 'Reject'] as $k => $l)
                                <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 {{ $decision === $k ? 'border-brand-400 bg-brand-50' : 'border-gray-200' }}"><input type="radio" wire:model.live="decision" value="{{ $k }}" class="text-brand-600 focus:ring-brand-500"> {{ $l }}</label>
                            @endforeach
                        </div>
                        @if ($decision)
                            <x-ui.textarea name="note" :label="$decision === 'approve' ? 'Note (optional)' : ($decision === 'reject' ? 'Reason for rejecting' : 'What do you need to know?')" wire:model="note" :required="$decision !== 'approve'" />
                            <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="close">Cancel</x-ui.button>
                                <x-ui.button type="submit" :variant="$decision === 'reject' ? 'danger' : 'primary'" wire:target="decide">{{ ['approve' => 'Approve payment', 'reject' => 'Reject payment', 'info' => 'Send question'][$decision] }}</x-ui.button></div>
                        @endif
                        @if ($errors->has('note') && ! $decision) <p class="text-sm text-red-600">{{ $errors->first('note') }}</p> @endif
                    </form>
                @else
                    <div class="flex justify-end"><x-ui.button variant="secondary" wire:click="close">Close</x-ui.button></div>
                @endif
            </div>
        </x-ui.modal>
    @endif
</div>
