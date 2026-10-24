<?php

use App\Actions\ReviewPaymentSubmission;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Payment;
use App\Models\PaymentSubmission;
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

    public ?int $deciding = null;       // submission being approved or rejected
    public string $decision = '';       // approve | reject
    public string $reason = '';

    public function mount(): void
    {
        $this->authorizePermission('payments.record');
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function rows()
    {
        return PaymentSubmission::with(['student', 'membership.plan', 'payment', 'reviewer'])
            ->when(in_array($this->status, ['pending', 'approved', 'rejected'], true), fn ($q) => $q->where('status', $this->status))
            ->latest('id')->paginate(15);
    }

    #[Computed]
    public function current(): ?PaymentSubmission
    {
        return $this->deciding ? PaymentSubmission::with(['student', 'membership.plan'])->find($this->deciding) : null;
    }

    public function start(int $id, string $decision): void
    {
        $this->authorizePermission('payments.record');
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422);
        PaymentSubmission::findOrFail($id);   // tenant-scoped

        $this->deciding = $id;
        $this->decision = $decision;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('deciding', 'decision', 'reason');
        unset($this->current);
    }

    public function confirm(): void
    {
        $this->authorizePermission('payments.record');
        $submission = PaymentSubmission::findOrFail($this->deciding);
        $review = app(ReviewPaymentSubmission::class);

        try {
            if ($this->decision === 'approve') {
                $review->approve($submission);
                $message = 'Payment recorded.';
            } else {
                $this->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tell the student why.']);
                $review->reject($submission, $this->reason);
                $message = 'Rejected. The student has been told.';
            }
        } catch (BusinessRuleException $e) {
            $this->addError($this->decision === 'reject' ? 'reason' : 'decision', $e->getMessage());

            return;
        }

        $this->cancel();
        unset($this->rows);
        $this->dispatch('notify', message: $message);
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Student payments" subtitle="Payments students say they made. Confirm to record them." /></x-slot:header>

    @php $slug = \App\Support\Tenancy::slug(); @endphp

    @unless (\App\Support\LibrarySettings::get('student_payment_submissions'))
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">Students cannot send payment details right now. Turn it on in Settings → Memberships.</div>
    @endunless

    <div class="flex flex-wrap gap-3">
        <x-ui.select name="status" wire:model.live="status">
            <option value="pending">Waiting for review</option>
            <option value="approved">Confirmed</option>
            <option value="rejected">Rejected</option>
            <option value="all">All</option>
        </x-ui.select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left text-xs text-gray-500">
                <th class="px-4 py-3 font-medium">Student</th><th class="px-4 py-3 font-medium">For</th><th class="px-4 py-3 text-right font-medium">Amount</th>
                <th class="px-4 py-3 font-medium">Method / reference</th><th class="px-4 py-3 font-medium">Paid on</th><th class="px-4 py-3 font-medium">Proof</th>
                <th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th>
            </tr></thead>
            <tbody>
                @forelse ($this->rows as $r)
                    <tr class="border-t border-gray-100" wire:key="ps-{{ $r->id }}">
                        <td class="px-4 py-3"><p class="font-medium text-gray-900">{{ $r->student->name }}</p><p class="text-xs text-gray-500">{{ $r->student->student_code }}</p></td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->membership?->plan?->name ?? '—' }}@if ($r->membership)<p class="text-xs text-gray-400">due ₹{{ number_format(max(0, (float) $r->membership->outstanding()), 2) }}</p>@endif</td>
                        <td class="px-4 py-3 text-right font-semibold">₹{{ number_format((float) $r->amount, 2) }}</td>
                        <td class="px-4 py-3"><p>{{ \App\Models\Payment::METHODS[$r->method] ?? $r->method }}</p><p class="text-xs text-gray-500">{{ $r->reference }}</p></td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->paid_on->format('d M Y') }}</td>
                        <td class="px-4 py-3">@if ($r->proof_path)<a href="{{ route('app.payment-submissions.proof', ['library' => $slug, 'submission' => $r->id]) }}" target="_blank" class="font-medium text-brand-600 hover:text-brand-800">View</a>@else<span class="text-gray-400">—</span>@endif</td>
                        <td class="px-4 py-3">
                            <x-ui.badge :color="match ($r->status) { 'approved' => 'green', 'rejected' => 'red', default => 'amber' }">{{ $r->status === 'pending' ? 'Waiting' : ($r->status === 'approved' ? 'Confirmed' : 'Rejected') }}</x-ui.badge>
                            @if ($r->status === 'rejected' && $r->review_note) <p class="mt-1 max-w-48 text-xs text-gray-500">{{ $r->review_note }}</p> @endif
                            @if ($r->payment) <p class="mt-1 text-xs text-gray-500">{{ $r->payment->receipt_no }}</p> @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if ($r->status === 'pending')
                                <button wire:click="start({{ $r->id }}, 'approve')" class="font-medium text-brand-600 hover:text-brand-800">Confirm</button>
                                <button wire:click="start({{ $r->id }}, 'reject')" class="ml-3 font-medium text-red-600 hover:text-red-800">Reject</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Nothing here.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->rows->links() }}

    @if ($this->current)
        @php $c = $this->current; @endphp
        <x-ui.modal :title="$decision === 'approve' ? 'Confirm payment' : 'Reject payment'" max="max-w-md" wire:key="decide-modal">
            <form wire:submit="confirm" class="space-y-4">
                <p class="text-sm text-gray-700"><strong>{{ $c->student->name }}</strong> says they paid <strong>₹{{ number_format((float) $c->amount, 2) }}</strong> by {{ \App\Models\Payment::METHODS[$c->method] ?? $c->method }}@if ($c->reference) (ref {{ $c->reference }})@endif on {{ $c->paid_on->format('d M Y') }}.</p>
                @if ($decision === 'approve')
                    <p class="text-xs text-gray-500">Check that the money reached you. Confirming records a normal payment and a receipt.</p>
                    @error('decision') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                @else
                    <x-ui.textarea name="reason" label="Reason (shown to the student)" required wire:model="reason" />
                @endif
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" wire:click="cancel">Cancel</x-ui.button>
                    <x-ui.button type="submit" :variant="$decision === 'approve' ? 'primary' : 'danger'">{{ $decision === 'approve' ? 'Confirm and record' : 'Reject' }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
