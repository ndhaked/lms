<?php

use App\Actions\RecordPayment;
use App\Actions\RecordRefund;
use App\Actions\ReversePayment;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\Student;
use App\Models\StudentMembership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $method = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    // record payment
    #[Url]
    public ?string $pay = null; // membership id coming from the dues page
    public bool $showPay = false;
    public string $studentSearch = '';
    public ?int $studentId = null;
    public string $membershipId = '';
    public string $amount = '';
    public string $payMethod = 'cash';
    public string $paidOn = '';
    public string $reference = '';
    public string $notes = '';
    public string $idempotencyKey = '';

    // refund
    public ?int $refundingId = null;
    public string $refundMembership = '';
    public string $refundAmount = '';
    public string $refundMethod = 'cash';
    public string $refundReason = '';

    // reverse
    public ?int $reversingId = null;
    public string $reverseReason = '';

    public function mount(): void
    {
        $this->authorizePermission('payments.view');

        if ($this->pay && $this->can('payments.record')) {
            $m = StudentMembership::find($this->pay);
            if ($m) {
                $this->openPay($m->student_id, (string) $m->id);
            }
        }
        $this->pay = null;
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'method', 'status', 'from', 'until'], true)) {
            $this->resetPage();
        }
    }

    // ---------- lists ----------
    #[Computed]
    public function payments()
    {
        return Payment::query()
            ->with(['student', 'recorder', 'refunds'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('receipt_no', 'like', "%{$this->search}%")
                ->orWhere('reference', 'like', "%{$this->search}%")
                ->orWhereHas('student', fn ($s) => $s->where('name', 'like', "%{$this->search}%")->orWhere('student_code', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%"))))
            ->when($this->method !== '', fn ($q) => $q->where('method', $this->method))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->from !== '', fn ($q) => $q->whereDate('paid_on', '>=', $this->from))
            ->when($this->until !== '', fn ($q) => $q->whereDate('paid_on', '<=', $this->until))
            ->latest('paid_on')->latest('id')
            ->paginate(15);
    }

    /** Money in minus refunds paid out, for a date range. Reversed payments never count. */
    protected function collected(Carbon $from, Carbon $to): string
    {
        $in = Payment::where('status', Payment::COMPLETED)->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->sum('amount');
        $out = Refund::whereBetween('refunded_on', [$from->toDateString(), $to->toDateString()])->sum('amount');

        return number_format((float) $in - (float) $out, 2);
    }

    #[Computed]
    public function today(): string
    {
        return $this->collected(today(), today());
    }

    #[Computed]
    public function month(): string
    {
        return $this->collected(now()->startOfMonth(), now()->endOfMonth());
    }

    #[Computed]
    public function dues(): string
    {
        return number_format((float) StudentMembership::owing()->withBalance()->get()->sum('balance'), 2);
    }

    // ---------- record payment ----------
    #[Computed]
    public function studentResults()
    {
        return Student::query()
            ->where('status', Student::ACTIVE)
            ->when($this->studentSearch !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->studentSearch}%")->orWhere('phone', 'like', "%{$this->studentSearch}%")->orWhere('student_code', 'like', "%{$this->studentSearch}%")))
            ->orderBy('name')->limit(6)->get(['id', 'name', 'phone', 'student_code']);
    }

    #[Computed]
    public function owingMemberships()
    {
        return $this->studentId
            ? StudentMembership::owing()->withBalance()->with('plan')->where('student_id', $this->studentId)->orderBy('ends_on')->get()
            : collect();
    }

    public function openPay(?int $studentId = null, string $membershipId = ''): void
    {
        $this->authorizePermission('payments.record');
        $this->reset(['studentSearch', 'amount', 'reference', 'notes']);
        $this->studentId = $studentId;
        $this->membershipId = $membershipId;
        $this->payMethod = 'cash';
        $this->paidOn = today()->toDateString();
        $this->idempotencyKey = (string) Str::uuid(); // a double click submits the same key, so it is recorded once
        $this->resetErrorBag();
        $this->prefillAmount();
        $this->showPay = true;
    }

    public function updatedStudentId(): void
    {
        $this->membershipId = '';
        $this->prefillAmount();
    }

    public function updatedMembershipId(): void
    {
        $this->prefillAmount();
    }

    protected function prefillAmount(): void
    {
        $list = $this->owingMemberships;
        $due = $this->membershipId !== '' ? $list->firstWhere('id', (int) $this->membershipId)?->balance : $list->sum('balance');
        $this->amount = $due ? number_format((float) $due, 2, '.', '') : '';
    }

    public function savePay(): void
    {
        $this->authorizePermission('payments.record');

        $this->validate([
            'studentId' => ['required', 'integer'],
            'membershipId' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payMethod' => ['required', 'in:'.implode(',', array_keys(Payment::METHODS))],
            'paidOn' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['studentId.required' => 'Choose a student.'], ['payMethod' => 'method', 'paidOn' => 'payment date']);

        try {
            $payment = app(RecordPayment::class)(
                Student::findOrFail($this->studentId), // tenant-scoped lookups: foreign ids are a 404
                $this->amount,
                $this->payMethod,
                Carbon::parse($this->paidOn),
                $this->membershipId !== '' ? StudentMembership::findOrFail($this->membershipId) : null,
                $this->reference ?: null,
                $this->notes ?: null,
                $this->idempotencyKey ?: null,
            );
        } catch (BusinessRuleException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->showPay = false;
        unset($this->payments, $this->today, $this->month, $this->dues);
        $this->dispatch('notify', message: "Payment recorded — receipt {$payment->receipt_no}.");
    }

    // ---------- refund ----------
    #[Computed]
    public function refundOptions()
    {
        if (! $this->refundingId) {
            return collect();
        }

        return PaymentAllocation::with('membership.plan')->where('payment_id', $this->refundingId)->get()->map(function ($a) {
            $done = (float) Refund::where('payment_id', $a->payment_id)->where('student_membership_id', $a->student_membership_id)->sum('amount');
            $a->refundable = round((float) $a->amount - $done, 2);

            return $a;
        })->filter(fn ($a) => $a->refundable > 0)->values();
    }

    public function startRefund(int $id): void
    {
        $this->authorizePermission('payments.refund');
        $payment = Payment::findOrFail($id);
        abort_if($payment->isReversed(), 422);

        $this->refundingId = $payment->id;
        $this->reset(['refundMembership', 'refundAmount', 'refundReason']);
        $this->refundMethod = $payment->method;
        $this->resetErrorBag();
        $first = $this->refundOptions->first();
        if ($first) {
            $this->refundMembership = (string) $first->student_membership_id;
            $this->refundAmount = number_format($first->refundable, 2, '.', '');
        }
    }

    public function updatedRefundMembership(): void
    {
        $opt = $this->refundOptions->firstWhere('student_membership_id', (int) $this->refundMembership);
        $this->refundAmount = $opt ? number_format($opt->refundable, 2, '.', '') : '';
    }

    public function saveRefund(): void
    {
        $this->authorizePermission('payments.refund');
        $this->validate([
            'refundMembership' => ['required', 'integer'],
            'refundAmount' => ['required', 'numeric', 'gt:0'],
            'refundMethod' => ['required', 'in:'.implode(',', array_keys(Payment::METHODS))],
            'refundReason' => ['required', 'string', 'max:500'],
        ], [], ['refundMembership' => 'membership', 'refundAmount' => 'amount', 'refundMethod' => 'method', 'refundReason' => 'reason']);

        try {
            $refund = app(RecordRefund::class)(
                Payment::findOrFail($this->refundingId),
                StudentMembership::findOrFail($this->refundMembership),
                $this->refundAmount, $this->refundMethod, $this->refundReason,
            );
        } catch (BusinessRuleException $e) {
            $this->addError('refundAmount', $e->getMessage());

            return;
        }

        $this->refundingId = null;
        unset($this->payments, $this->today, $this->month, $this->dues);
        $this->dispatch('notify', message: "Refund {$refund->refund_no} recorded.");
    }

    // ---------- reverse ----------
    public function startReverse(int $id): void
    {
        $this->authorizePermission('payments.refund');
        Payment::findOrFail($id);
        $this->reversingId = $id;
        $this->reverseReason = '';
        $this->resetErrorBag();
    }

    public function saveReverse(): void
    {
        $this->authorizePermission('payments.refund');
        $this->validate(['reverseReason' => ['required', 'string', 'max:500']], [], ['reverseReason' => 'reason']);

        try {
            app(ReversePayment::class)(Payment::findOrFail($this->reversingId), $this->reverseReason);
        } catch (BusinessRuleException $e) {
            $this->addError('reverseReason', $e->getMessage());

            return;
        }

        $this->reversingId = null;
        unset($this->payments, $this->today, $this->month, $this->dues);
        $this->dispatch('notify', message: 'Payment reversed. The record is kept.');
    }

    public function closeModal(): void
    {
        $this->showPay = false;
        $this->refundingId = null;
        $this->reversingId = null;
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Payments" subtitle="Fees collected from students" /></x-slot:header>

    @php $slug = \App\Support\Tenancy::slug(); @endphp

    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat label="Collected today" :value="'₹'.$this->today" icon="cash" tone="green" hint="Payments minus refunds" />
        <x-ui.stat label="Collected this month" :value="'₹'.$this->month" icon="chart" tone="blue" hint="Payments minus refunds" />
        <a href="{{ route('app.dues.index', ['library' => $slug]) }}" wire:navigate class="block"><x-ui.stat label="Outstanding dues" :value="'₹'.$this->dues" icon="alert" tone="red" hint="View who owes" /></a>
    </div>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="flex flex-wrap items-end gap-3">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Receipt, student, reference…" class="w-60 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <select wire:model.live="method" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">Any method</option>
                @foreach (\App\Models\Payment::METHODS as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach
            </select>
            <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">Any status</option><option value="completed">Completed</option><option value="reversed">Reversed</option>
            </select>
            <label class="text-xs text-gray-500">From <input type="date" wire:model.live="from" class="ml-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></label>
            <label class="text-xs text-gray-500">Until <input type="date" wire:model.live="until" class="ml-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></label>
        </div>
        @if ($this->can('payments.record'))
            <x-ui.button wire:click="openPay"><x-lm.icon name="plus" class="h-4 w-4" /> Record payment</x-ui.button>
        @endif
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Receipt</th><th class="px-4 py-3 font-medium">Student</th><th class="px-4 py-3 text-right font-medium">Amount</th><th class="px-4 py-3 font-medium">Method</th><th class="px-4 py-3 font-medium">Date</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3 font-medium">By</th><th class="px-4 py-3 text-right font-medium">Actions</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->payments as $p)
                        @php $refunded = (float) $p->refunds->sum('amount'); @endphp
                        <tr wire:key="pay-{{ $p->id }}" class="{{ $p->isReversed() ? 'bg-gray-50/70 text-gray-400' : '' }}">
                            <td class="px-4 py-3 font-medium {{ $p->isReversed() ? 'line-through' : 'text-gray-900' }}">{{ $p->receipt_no }}</td>
                            <td class="px-4 py-3"><div>{{ $p->student->name }}</div><div class="text-xs text-gray-400">{{ $p->student->student_code }}</div></td>
                            <td class="px-4 py-3 text-right">
                                <span class="font-semibold {{ $p->isReversed() ? 'line-through' : 'text-gray-900' }}">₹{{ number_format((float) $p->amount, 2) }}</span>
                                @if ($refunded > 0) <div class="text-xs text-amber-600">₹{{ number_format($refunded, 2) }} refunded</div> @endif
                            </td>
                            <td class="px-4 py-3">{{ \App\Models\Payment::METHODS[$p->method] ?? $p->method }}@if ($p->reference)<div class="text-xs text-gray-400">{{ $p->reference }}</div>@endif</td>
                            <td class="px-4 py-3">{{ $p->paid_on->format('d M Y') }}</td>
                            <td class="px-4 py-3">
                                @if ($p->isReversed()) <x-ui.badge color="red" title="{{ $p->reversal_reason }}">Reversed</x-ui.badge> @else <x-ui.badge color="green">Completed</x-ui.badge> @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ $p->recorder?->name ?? '—' }}</td>
                            <td class="space-x-3 px-4 py-3 text-right">
                                @if ($this->can('receipts.print'))
                                    <a href="{{ route('app.payments.receipt', ['library' => $slug, 'payment' => $p->id]) }}" target="_blank" class="font-medium text-brand-600 hover:text-brand-800">Receipt</a>
                                @endif
                                @if (! $p->isReversed() && $this->can('payments.refund'))
                                    <button wire:click="startRefund({{ $p->id }})" class="font-medium text-amber-600 hover:text-amber-800">Refund</button>
                                    @if ($refunded == 0) <button wire:click="startReverse({{ $p->id }})" class="font-medium text-red-600 hover:text-red-800">Reverse</button> @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">No payments found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->payments->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->payments->links() }}</div> @endif
    </div>

    {{-- record payment --}}
    @if ($showPay)
        <x-ui.modal title="Record payment" max="max-w-xl" wire:key="pay-modal">
            <form wire:submit="savePay" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Student <span class="text-red-500">*</span></label>
                    <input wire:model.live.debounce.250ms="studentSearch" type="search" placeholder="Search name, phone or student ID…" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                    <ul class="mt-2 space-y-1">
                        @foreach ($this->studentResults as $st)
                            <li wire:key="ps-{{ $st->id }}">
                                <label class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm {{ $studentId === $st->id ? 'border-brand-400 bg-brand-50' : 'border-gray-200 hover:bg-gray-50' }}">
                                    <input type="radio" wire:model.live="studentId" value="{{ $st->id }}" class="text-brand-600 focus:ring-brand-500">
                                    <span class="flex-1 font-medium">{{ $st->name }}</span><span class="text-xs text-gray-400">{{ $st->student_code }} · {{ $st->phone }}</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    @error('studentId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                @if ($studentId)
                    <x-ui.select name="membershipId" label="Apply to" wire:model.live="membershipId" hint="Leave on “Oldest first” to clear the oldest outstanding membership first.">
                        <option value="">Oldest first (₹{{ number_format((float) $this->owingMemberships->sum('balance'), 2) }} outstanding)</option>
                        @foreach ($this->owingMemberships as $m)
                            <option value="{{ $m->id }}">{{ $m->plan->name }} · {{ $m->starts_on->format('d M') }}–{{ $m->ends_on->format('d M Y') }} · owes ₹{{ number_format((float) $m->balance, 2) }}</option>
                        @endforeach
                    </x-ui.select>
                    @if ($this->owingMemberships->isEmpty())
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700">This student has nothing outstanding.</p>
                    @endif
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="amount" label="Amount (₹)" type="number" step="0.01" min="0" required wire:model="amount" />
                    <x-ui.select name="payMethod" label="Method" wire:model="payMethod">@foreach (\App\Models\Payment::METHODS as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach</x-ui.select>
                    <x-ui.input name="paidOn" label="Payment date" type="date" required wire:model="paidOn" />
                    <x-ui.input name="reference" label="Reference / UTR" wire:model="reference" />
                </div>
                <x-ui.textarea name="notes" label="Notes" wire:model="notes" />

                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button>
                    <x-ui.button type="submit" wire:target="savePay"><span wire:loading.remove wire:target="savePay">Record payment</span><span wire:loading wire:target="savePay">Saving…</span></x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    {{-- refund --}}
    @if ($refundingId)
        <x-ui.modal title="Refund payment" max="max-w-lg" wire:key="refund-modal">
            @if ($this->refundOptions->isEmpty())
                <p class="text-sm text-gray-600">Nothing is left to refund on this payment.</p>
                <div class="mt-4 flex justify-end"><x-ui.button variant="secondary" wire:click="closeModal">Close</x-ui.button></div>
            @else
                <form wire:submit="saveRefund" class="space-y-4">
                    <x-ui.select name="refundMembership" label="Refund against" wire:model.live="refundMembership">
                        @foreach ($this->refundOptions as $o)
                            <option value="{{ $o->student_membership_id }}">{{ $o->membership->plan->name }} · up to ₹{{ number_format($o->refundable, 2) }}</option>
                        @endforeach
                    </x-ui.select>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input name="refundAmount" label="Refund amount (₹)" type="number" step="0.01" min="0" required wire:model="refundAmount" />
                        <x-ui.select name="refundMethod" label="Paid back by" wire:model="refundMethod">@foreach (\App\Models\Payment::METHODS as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach</x-ui.select>
                    </div>
                    <x-ui.textarea name="refundReason" label="Reason" required wire:model="refundReason" />
                    <p class="text-xs text-gray-500">The original payment is kept; the refund is recorded separately.</p>
                    <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit" variant="warning">Record refund</x-ui.button></div>
                </form>
            @endif
        </x-ui.modal>
    @endif

    {{-- reverse --}}
    @if ($reversingId)
        <x-ui.modal title="Reverse payment" max="max-w-lg" wire:key="reverse-modal">
            <p class="mb-3 text-sm text-gray-600">Use this only for a payment recorded by mistake. The record stays on file, marked reversed, and no longer counts toward what the student has paid.</p>
            <form wire:submit="saveReverse" class="space-y-4">
                <x-ui.textarea name="reverseReason" label="Reason" required wire:model="reverseReason" />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit" variant="danger">Reverse payment</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
