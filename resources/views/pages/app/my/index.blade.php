<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Attendance;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\RenewalRequest;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Support\Notifier;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithFileUploads;

    public bool $showRenew = false;
    public string $planId = '';
    public string $message = '';

    // "I have paid" details
    public bool $showPaid = false;
    public string $subMembership = '';
    public string $subAmount = '';
    public string $subMethod = 'upi';
    public string $subReference = '';
    public string $subPaidOn = '';
    public string $subNote = '';
    public $subProof = null;

    public function mount(): void
    {
        $this->authorizePermission('self.view');
        abort_unless($this->student, 404, 'No student record is linked to your login.');
    }

    /** The student linked to THIS login. There is deliberately no way to ask for someone else. */
    #[Computed]
    public function student(): ?Student
    {
        return Student::with('activeAllocation.seat.hall')->where('user_id', auth()->id())->first();
    }

    #[Computed]
    public function memberships()
    {
        return StudentMembership::with('plan')->withBalance()->where('student_id', $this->student->id)->orderByDesc('ends_on')->get();
    }

    #[Computed]
    public function attendance()
    {
        return Attendance::with('seat')->where('student_id', $this->student->id)->latest('checked_in_at')->limit(20)->get();
    }

    #[Computed]
    public function payments()
    {
        return Payment::where('student_id', $this->student->id)->latest('paid_on')->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function plans()
    {
        return MembershipPlan::where('status', 'active')->orderBy('price')->get();
    }

    #[Computed]
    public function pendingRequest(): ?RenewalRequest
    {
        return RenewalRequest::where('student_id', $this->student->id)->where('status', 'pending')->latest('id')->first();
    }

    // ---- library books (only when the library uses the Books module) ----
    protected function booksEnabled(): bool
    {
        return \App\Support\Entitlements::hasModule('books');
    }

    #[Computed]
    public function myBooks()
    {
        return $this->booksEnabled()
            ? \App\Models\LoanItem::with(['book', 'copy'])->open()->where('student_id', $this->student->id)->orderBy('due_on')->get()
            : collect();
    }

    #[Computed]
    public function myFines()
    {
        return $this->booksEnabled() ? \App\Models\Fine::pending()->where('student_id', $this->student->id)->latest('id')->get() : collect();
    }

    // ---- reservations ----
    public string $reserveQuery = '';

    protected function reservationsEnabled(): bool
    {
        return $this->booksEnabled() && \App\Support\LibrarySettings::get('reservations_enabled');
    }

    #[Computed]
    public function myReservations()
    {
        return $this->reservationsEnabled()
            ? \App\Models\Reservation::with(['book', 'copy'])->active()->where('student_id', $this->student->id)->orderBy('requested_at')->get()
            : collect();
    }

    #[Computed]
    public function reserveResults()
    {
        $q = trim($this->reserveQuery);
        if (! $this->reservationsEnabled() || mb_strlen($q) < 2) {
            return collect();
        }

        return \App\Models\Book::where('type', 'physical')->where('status', 'active')->search($q)
            ->withCount(['copies as available' => fn ($c) => $c->where('status', 'available')])->orderBy('title')->limit(6)->get();
    }

    public function reserveBook(int $bookId): void
    {
        $this->authorizePermission('self.view');

        try {
            app(\App\Actions\Circulation\Reservations::class)->self(\App\Models\Book::findOrFail($bookId));
            $this->dispatch('notify', message: 'Reserved. We will tell you when it is ready.');
            $this->reserveQuery = '';
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
        unset($this->myReservations, $this->reserveResults);
    }

    public function cancelReservation(int $id): void
    {
        $this->authorizePermission('self.view');
        $r = \App\Models\Reservation::where('student_id', $this->student->id)->findOrFail($id);   // only the member's own

        try {
            app(\App\Actions\Circulation\Reservations::class)->cancel($r);
            $this->dispatch('notify', message: 'Reservation cancelled.');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
        unset($this->myReservations);
    }

    public function renewBook(int $itemId): void
    {
        $this->authorizePermission('self.view');
        $item = \App\Models\LoanItem::where('student_id', $this->student->id)->findOrFail($itemId);   // only the student's own books

        try {
            $item = app(\App\Actions\Circulation\RenewLoanItem::class)->self($item);
            $this->dispatch('notify', message: "Renewed. New due date: {$item->due_on->format('d M Y')}.");
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
        unset($this->myBooks);
    }

    public function openRenew(): void
    {
        $this->authorizePermission('self.view');
        abort_if($this->pendingRequest, 422);
        $this->reset(['planId', 'message']);
        $this->planId = (string) ($this->memberships->first()?->membership_plan_id ?? '');
        $this->resetErrorBag();
        $this->showRenew = true;
    }

    public function sendRenew(): void
    {
        $this->authorizePermission('self.view');
        $this->validate([
            'planId' => ['nullable', \Illuminate\Validation\Rule::exists('membership_plans', 'id')->where('library_id', Tenancy::currentId())->where('status', 'active')],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        if ($this->pendingRequest) {
            $this->addError('message', 'You already have a request waiting.');

            return;
        }

        RenewalRequest::create([
            'student_id' => $this->student->id,
            'membership_plan_id' => $this->planId !== '' ? $this->planId : null,
            'message' => $this->message ?: null,
        ]);

        Notifier::library(Tenancy::library(), 'memberships.manage', 'membership.renewal_requested', "{$this->student->name} asked to renew",
            $this->message ?: 'Open Memberships to respond.', route('app.memberships.index', ['library' => Tenancy::slug()]));

        $this->showRenew = false;
        unset($this->pendingRequest);
        $this->dispatch('notify', message: 'Request sent. The library will contact you.');
    }

    protected function submissionsEnabled(): bool
    {
        return (bool) \App\Support\LibrarySettings::get('student_payment_submissions');
    }

    /** Memberships the student still owes on, with what is left after claims already waiting for review. */
    #[Computed]
    public function payable()
    {
        $waiting = \App\Models\PaymentSubmission::where('student_id', $this->student->id)->where('status', 'pending')
            ->selectRaw('student_membership_id, SUM(amount) as total')->groupBy('student_membership_id')->pluck('total', 'student_membership_id');

        return $this->memberships->where('status', '!=', 'cancelled')
            ->map(function ($m) use ($waiting) {
                $m->claimable = bcsub(number_format(max(0, (float) $m->balance), 2, '.', ''), number_format((float) ($waiting[$m->id] ?? 0), 2, '.', ''), 2);

                return $m;
            })
            ->filter(fn ($m) => bccomp($m->claimable, '0.00', 2) > 0)->values();
    }

    #[Computed]
    public function submissions()
    {
        return \App\Models\PaymentSubmission::with('payment')->where('student_id', $this->student->id)->latest('id')->limit(10)->get();
    }

    public function openPaid(): void
    {
        $this->authorizePermission('self.view');
        abort_unless($this->submissionsEnabled(), 403);
        $this->reset(['subMembership', 'subAmount', 'subReference', 'subNote', 'subProof']);
        $this->subMethod = 'upi';
        $this->subPaidOn = today()->toDateString();
        $first = $this->payable->first();
        if ($first) {
            $this->subMembership = (string) $first->id;
            $this->subAmount = $first->claimable;
        }
        $this->resetErrorBag();
        $this->showPaid = true;
    }

    public function updatedSubMembership(): void
    {
        $m = $this->payable->firstWhere('id', (int) $this->subMembership);
        $this->subAmount = $m ? $m->claimable : '';
    }

    public function sendPaid(): void
    {
        $this->authorizePermission('self.view');
        abort_unless($this->submissionsEnabled(), 403);

        $d = $this->validate([
            'subMembership' => ['required', 'integer'],
            'subAmount' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'subMethod' => ['required', 'in:'.implode(',', array_keys(Payment::METHODS))],
            'subReference' => [$this->subMethod === 'cash' ? 'nullable' : 'required', 'string', 'max:100'],
            'subPaidOn' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.today()->subDays(90)->toDateString()],
            'subNote' => ['nullable', 'string', 'max:500'],
            'subProof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ], ['subMembership.required' => 'Choose what you paid for.', 'subReference.required' => 'Enter the transaction or reference number.'],
            ['subMembership' => 'membership', 'subAmount' => 'amount', 'subMethod' => 'method', 'subReference' => 'reference', 'subPaidOn' => 'payment date', 'subNote' => 'note', 'subProof' => 'proof']);

        $m = $this->payable->firstWhere('id', (int) $d['subMembership']);   // only the student's own, still-owing memberships
        if (! $m) {
            $this->addError('subMembership', 'Choose one of your memberships with an amount due.');

            return;
        }

        $amount = number_format((float) $d['subAmount'], 2, '.', '');
        if (bccomp($amount, $m->claimable, 2) > 0) {
            $this->addError('subAmount', 'That is more than the amount due (₹'.number_format((float) $m->claimable, 2).').');

            return;
        }
        if (\App\Models\PaymentSubmission::where('student_id', $this->student->id)->where('status', 'pending')->count() >= 3) {
            $this->addError('subAmount', 'You already have 3 payments waiting. Please wait for the library to confirm them.');

            return;
        }
        if (filled($d['subReference']) && \App\Models\PaymentSubmission::where('reference', $d['subReference'])->whereIn('status', ['pending', 'approved'])->exists()) {
            $this->addError('subReference', 'This reference number has already been submitted.');

            return;
        }

        \App\Models\PaymentSubmission::create([
            'student_id' => $this->student->id,
            'student_membership_id' => $m->id,
            'amount' => $amount,
            'method' => $d['subMethod'],
            'reference' => $d['subReference'] ?: null,
            'paid_on' => $d['subPaidOn'],
            'note' => $d['subNote'] ?: null,
            'proof_path' => $this->subProof ? $this->subProof->store('payment-proofs/'.Tenancy::currentId(), 'local') : null,
        ]);

        Notifier::library(Tenancy::library(), 'payments.record', 'payment_submission.new', "{$this->student->name} says they paid ₹".number_format((float) $amount, 2),
            'Open Student Payments to confirm or reject.', route('app.payment-submissions.index', ['library' => Tenancy::slug()]));

        $this->showPaid = false;
        $this->reset('subProof');
        unset($this->payable, $this->submissions);
        $this->dispatch('notify', message: 'Sent. The library will confirm your payment.');
    }

    public function closeModal(): void
    {
        $this->showRenew = false;
        $this->showPaid = false;
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="My library" subtitle="Your membership, seat and payments" /></x-slot:header>

    @php
        $s = $this->student;
        $current = $this->memberships->first(fn ($m) => $m->effectiveStatus() === 'active');
        $due = $this->memberships->where('status', '!=', 'cancelled')->sum(fn ($m) => max(0, (float) $m->balance));
        $slug = \App\Support\Tenancy::slug();
        $stateColor = fn ($st) => match ($st) { 'active' => 'green', 'upcoming' => 'blue', 'expired' => 'red', 'suspended' => 'amber', default => 'gray' };
    @endphp

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat label="Student ID" :value="$s->student_code" icon="users" tone="green" :hint="$s->name" />
        <x-ui.stat label="Membership" :value="$current ? $current->plan->name : 'None'" icon="badge" tone="blue" :hint="$current ? 'Valid until '.$current->ends_on->format('d M Y') : 'No running membership'" />
        <x-ui.stat label="My seat" :value="$s->activeAllocation?->seat?->code ?? '—'" icon="seat" tone="purple" :hint="$s->activeAllocation?->seat?->hall?->name ?? 'No seat allocated'" />
        <x-ui.stat label="Amount due" :value="'₹'.number_format($due, 2)" icon="cash" :tone="$due > 0 ? 'red' : 'green'" :hint="$due > 0 ? 'Please pay at the desk' : 'All paid up'" />
    </div>

    <div class="flex flex-wrap items-center gap-3">
        @if ($this->pendingRequest)
            <span class="rounded-lg bg-blue-50 px-4 py-2 text-sm text-blue-800">Your renewal request from {{ $this->pendingRequest->created_at->format('d M') }} is waiting for the library.</span>
        @else
            <x-ui.button wire:click="openRenew"><x-lm.icon name="refresh" class="h-4 w-4" /> Request renewal</x-ui.button>
        @endif
        @if (\App\Support\LibrarySettings::get('student_payment_submissions') && $this->payable->isNotEmpty())
            <x-ui.button variant="secondary" wire:click="openPaid"><x-lm.icon name="cash" class="h-4 w-4" /> I have paid</x-ui.button>
        @endif
    </div>

    <section class="rounded-xl border border-gray-200 bg-white p-5">
        <h3 class="mb-3 text-sm font-semibold text-gray-900">My memberships</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-gray-500"><th class="pb-2 font-medium">Plan</th><th class="pb-2 font-medium">Period</th><th class="pb-2 text-right font-medium">Charge</th><th class="pb-2 text-right font-medium">Paid</th><th class="pb-2 text-right font-medium">Balance</th><th class="pb-2 pl-4 font-medium">Status</th></tr></thead>
                <tbody>
                    @forelse ($this->memberships as $m)
                        <tr class="border-t border-gray-100" wire:key="mm-{{ $m->id }}">
                            <td class="py-2 font-medium">{{ $m->plan->name }}</td><td class="py-2 text-gray-600">{{ $m->starts_on->format('d M') }} – {{ $m->ends_on->format('d M Y') }}</td>
                            <td class="py-2 text-right">₹{{ number_format((float) $m->charge, 2) }}</td><td class="py-2 text-right text-gray-600">₹{{ number_format((float) $m->paid, 2) }}</td>
                            <td class="py-2 text-right font-semibold {{ (float) $m->balance > 0 ? 'text-red-600' : 'text-gray-400' }}">₹{{ number_format(max(0, (float) $m->balance), 2) }}</td>
                            <td class="py-2 pl-4"><x-ui.badge :color="$stateColor($m->effectiveStatus())">{{ ucfirst($m->effectiveStatus()) }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-400">No memberships yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-4 xl:grid-cols-2">
        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-3 text-sm font-semibold text-gray-900">Recent attendance</h3>
            <ul class="divide-y divide-gray-100 text-sm">
                @forelse ($this->attendance as $a)
                    <li class="flex items-center justify-between py-2" wire:key="at-{{ $a->id }}"><span>{{ $a->checked_in_at->format('d M Y') }} · {{ $a->checked_in_at->format('H:i') }} – {{ $a->checked_out_at?->format('H:i') ?? 'inside' }}</span><span class="text-xs text-gray-500">{{ $a->seat?->code }}@if ($a->is_late) <x-ui.badge color="amber" class="ml-1">Late</x-ui.badge>@endif</span></li>
                @empty
                    <li class="py-6 text-center text-gray-400">No visits recorded yet.</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-3 text-sm font-semibold text-gray-900">My payments</h3>
            <ul class="divide-y divide-gray-100 text-sm">
                @forelse ($this->payments as $p)
                    <li class="flex items-center justify-between py-2" wire:key="py-{{ $p->id }}">
                        <span>{{ $p->paid_on->format('d M Y') }} · {{ $p->receipt_no }}@if ($p->isReversed()) <x-ui.badge color="red" class="ml-1">Reversed</x-ui.badge>@endif</span>
                        <span class="flex items-center gap-3"><span class="font-semibold">₹{{ number_format((float) $p->amount, 2) }}</span><a href="{{ route('app.my.receipt', ['library' => $slug, 'payment' => $p->id]) }}" target="_blank" class="text-xs font-medium text-brand-600 hover:text-brand-800">Receipt</a></span>
                    </li>
                @empty
                    <li class="py-6 text-center text-gray-400">No payments yet.</li>
                @endforelse
            </ul>
        </section>
    </div>

    @if (\App\Support\Entitlements::hasModule('books') && ($this->myBooks->isNotEmpty() || $this->myFines->isNotEmpty()))
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl border border-gray-200 bg-white p-5">
                <h3 class="mb-3 text-sm font-semibold text-gray-900">Books I have borrowed</h3>
                <ul class="divide-y divide-gray-100 text-sm">
                    @forelse ($this->myBooks as $i)
                        @php $late = $i->due_on->lt(today()); @endphp
                        <li class="py-2.5" wire:key="mb-{{ $i->id }}">
                            <div class="flex items-center justify-between gap-3"><span class="font-medium text-gray-900">{{ $i->book->title }}</span><x-ui.badge :color="$late ? 'red' : ($i->due_on->isToday() ? 'amber' : 'green')">{{ $late ? 'Overdue' : 'Due '.$i->due_on->format('d M') }}</x-ui.badge></div>
                            <div class="mt-0.5 flex items-center justify-between text-xs text-gray-500">
                                <span>Due {{ $i->due_on->format('d M Y') }}@if ($i->renewals) · renewed {{ $i->renewals }}× @endif</span>
                                @if (\App\Support\LibrarySettings::get('student_can_renew') && ! \App\Actions\Circulation\RenewLoanItem::blockReason($i)) <button wire:click="renewBook({{ $i->id }})" class="font-medium text-brand-600 hover:text-brand-800">Renew</button> @endif
                            </div>
                        </li>
                    @empty
                        <li class="py-6 text-center text-gray-400">No books borrowed.</li>
                    @endforelse
                </ul>
            </section>
            <section class="rounded-xl border border-gray-200 bg-white p-5">
                <h3 class="mb-3 text-sm font-semibold text-gray-900">Library fines</h3>
                <ul class="divide-y divide-gray-100 text-sm">
                    @forelse ($this->myFines as $f)
                        <li class="flex items-center justify-between py-2.5" wire:key="mf-{{ $f->id }}"><span>{{ \App\Models\Fine::TYPES[$f->type] ?? $f->type }}<span class="block text-xs text-gray-500">{{ $f->note }}</span></span><span class="font-semibold text-red-600">₹{{ number_format((float) $f->outstanding(), 2) }}</span></li>
                    @empty
                        <li class="py-6 text-center text-gray-400">No fines.</li>
                    @endforelse
                </ul>
                @if ($this->myFines->isNotEmpty()) <p class="mt-2 text-xs text-gray-500">Please pay fines at the library desk.</p> @endif
            </section>
        </div>
    @endif

    @if (\App\Support\Entitlements::hasModule('books') && \App\Support\LibrarySettings::get('reservations_enabled'))
        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-3 text-sm font-semibold text-gray-900">Reserve a book</h3>
            @if ($this->myReservations->isNotEmpty())
                <ul class="mb-4 divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm">
                    @foreach ($this->myReservations as $r)
                        <li class="flex items-center justify-between gap-3 px-3 py-2" wire:key="mr-{{ $r->id }}">
                            <span class="min-w-0"><span class="block truncate font-medium text-gray-900">{{ $r->book->title }}</span>
                                <span class="text-xs {{ $r->status === 'ready' ? 'font-semibold text-brand-700' : 'text-gray-500' }}">{{ $r->status === 'ready' ? 'Ready! Collect from the desk by '.$r->expires_on->format('d M Y') : 'Waiting for a copy' }}</span></span>
                            <button wire:click="cancelReservation({{ $r->id }})" wire:confirm="Cancel this reservation?" class="text-xs font-medium text-red-600 hover:text-red-800">Cancel</button>
                        </li>
                    @endforeach
                </ul>
            @endif
            <input type="search" wire:model.live.debounce.300ms="reserveQuery" placeholder="Search a book that is out, by title or author…" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <ul class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm {{ $this->reserveResults->isEmpty() ? 'hidden' : '' }}">
                @foreach ($this->reserveResults as $b)
                    <li class="flex items-center justify-between gap-3 px-3 py-2" wire:key="rb-{{ $b->id }}">
                        <span class="min-w-0"><span class="block truncate font-medium text-gray-900">{{ $b->title }}</span><span class="text-xs {{ $b->available > 0 ? 'text-brand-700' : 'text-gray-500' }}">{{ $b->available > 0 ? 'On the shelf: ask at the desk' : 'All copies are out' }}</span></span>
                        @if ($b->available === 0) <x-ui.button wire:click="reserveBook({{ $b->id }})" wire:key="rbb-{{ $b->id }}">Reserve</x-ui.button> @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if (\App\Support\LibrarySettings::get('student_payment_submissions') && $this->submissions->isNotEmpty())
        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-3 text-sm font-semibold text-gray-900">Payment details I sent</h3>
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach ($this->submissions as $sub)
                    <li class="py-2" wire:key="sub-{{ $sub->id }}">
                        <div class="flex items-center justify-between gap-3">
                            <span>{{ $sub->paid_on->format('d M Y') }} · ₹{{ number_format((float) $sub->amount, 2) }} · {{ \App\Models\Payment::METHODS[$sub->method] ?? $sub->method }}@if ($sub->reference) · {{ $sub->reference }}@endif</span>
                            <x-ui.badge :color="match ($sub->status) { 'approved' => 'green', 'rejected' => 'red', default => 'amber' }">{{ $sub->status === 'pending' ? 'Waiting for library' : ucfirst($sub->status) }}</x-ui.badge>
                        </div>
                        @if ($sub->status === 'rejected' && $sub->review_note) <p class="mt-1 text-xs text-red-700">{{ $sub->review_note }}</p> @endif
                        @if ($sub->payment) <a href="{{ route('app.my.receipt', ['library' => $slug, 'payment' => $sub->payment->id]) }}" target="_blank" class="text-xs font-medium text-brand-600 hover:text-brand-800">Receipt {{ $sub->payment->receipt_no }}</a> @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($showPaid)
        <x-ui.modal title="I have paid" max="max-w-lg" wire:key="paid-modal">
            <form wire:submit="sendPaid" class="space-y-4">
                <p class="text-xs text-gray-500">Tell the library about a payment you already made. It is added to your account only after the library confirms it.</p>
                <x-ui.select name="subMembership" label="Paid for" required wire:model.live="subMembership">
                    @foreach ($this->payable as $m) <option value="{{ $m->id }}">{{ $m->plan->name }} ({{ $m->starts_on->format('d M') }} – {{ $m->ends_on->format('d M Y') }}) — due ₹{{ number_format((float) $m->claimable, 2) }}</option> @endforeach
                </x-ui.select>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="subAmount" label="Amount (₹)" type="number" step="0.01" min="0.01" required wire:model="subAmount" />
                    <x-ui.input name="subPaidOn" label="Payment date" type="date" required wire:model="subPaidOn" />
                    <x-ui.select name="subMethod" label="Method" required wire:model.live="subMethod">
                        @foreach (\App\Models\Payment::METHODS as $key => $label) <option value="{{ $key }}">{{ $label }}</option> @endforeach
                    </x-ui.select>
                    <x-ui.input name="subReference" :label="$subMethod === 'cash' ? 'Reference (optional)' : 'Transaction / reference no.'" :required="$subMethod !== 'cash'" wire:model="subReference" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Screenshot or receipt (optional)</label>
                    <input type="file" wire:model="subProof" accept=".jpg,.jpeg,.png,.pdf" class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700">
                    @error('subProof') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <x-ui.textarea name="subNote" label="Note (optional)" wire:model="subNote" />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit" wire:target="sendPaid,subProof">Send</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    @if ($showRenew)
        <x-ui.modal title="Request renewal" max="max-w-md" wire:key="renew-modal">
            <form wire:submit="sendRenew" class="space-y-4">
                <x-ui.select name="planId" label="Plan you would like" wire:model="planId" hint="The library confirms the plan and price with you.">
                    <option value="">Same as now / not sure</option>
                    @foreach ($this->plans as $p) <option value="{{ $p->id }}">{{ $p->name }} — ₹{{ number_format((float) $p->price) }}</option> @endforeach
                </x-ui.select>
                <x-ui.textarea name="message" label="Message (optional)" wire:model="message" />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Send request</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
