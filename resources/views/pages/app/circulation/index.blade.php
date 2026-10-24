<?php

use App\Actions\Circulation\IssueBooks;
use App\Actions\Circulation\RenewLoanItem;
use App\Actions\Circulation\ReturnBooks;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\BookCopy;
use App\Models\Fine;
use App\Models\LoanItem;
use App\Models\Student;
use App\Support\CirculationRules;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions;

    #[Url]
    public string $tab = 'issue';          // issue | return

    // member
    public string $memberSearch = '';
    #[Url(as: 'member')]
    public ?int $studentId = null;

    // issue
    public string $scan = '';
    public array $cart = [];               // [['barcode', 'accession', 'title']]
    public string $notes = '';
    public ?array $issued = null;          // the finished transaction, for the confirmation panel

    // return
    public string $returnScan = '';
    public array $returnCart = [];         // [['item', 'title', 'accession', 'member', 'due', 'late', 'fine', 'condition', 'fee']]
    public ?array $returned = null;

    public function mount(): void
    {
        abort_unless($this->can('circulation.issue') || $this->can('circulation.return'), 403);
        if (! $this->can('circulation.issue')) {
            $this->tab = 'return';
        }
        if ($this->studentId && ! Student::find($this->studentId)) {
            $this->studentId = null;
        }
    }

    // ---------------- member ----------------

    #[Computed]
    public function results()
    {
        $q = trim($this->memberSearch);
        if (mb_strlen($q) < 2 || $this->studentId) {
            return collect();
        }

        return Student::where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('student_code', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"))
            ->orderBy('name')->limit(6)->get();
    }

    #[Computed]
    public function member(): ?Student
    {
        return $this->studentId ? Student::find($this->studentId) : null;
    }

    #[Computed]
    public function openItems()
    {
        return $this->studentId ? LoanItem::with(['book', 'copy'])->open()->where('student_id', $this->studentId)->orderBy('due_on')->get() : collect();
    }

    #[Computed]
    public function unpaidFines(): float
    {
        return $this->studentId ? (float) Fine::where('student_id', $this->studentId)->pending()->get()->sum(fn ($f) => (float) $f->outstanding()) : 0.0;
    }

    public function pickMember(int $id): void
    {
        $this->studentId = Student::findOrFail($id)->id;   // tenant-scoped
        $this->memberSearch = '';
        $this->reset('cart', 'issued', 'returned');
        $this->resetErrorBag();
        unset($this->member, $this->openItems, $this->unpaidFines, $this->results);
        $this->dispatch('focus-scan');
    }

    /** Enter in the member box: an exact student ID, phone or email picks them straight away (a scanned ID card). */
    public function findMember(): void
    {
        $q = trim($this->memberSearch);
        $exact = Student::where('student_code', $q)->orWhere('phone', $q)->orWhere('email', $q)->get();
        if ($exact->count() === 1) {
            $this->pickMember($exact->first()->id);
        } elseif ($this->results->count() === 1) {
            $this->pickMember($this->results->first()->id);
        }
    }

    public function clearMember(): void
    {
        $this->reset('studentId', 'cart', 'issued', 'memberSearch');
        unset($this->member, $this->openItems, $this->unpaidFines);
    }

    // ---------------- issue ----------------

    #[Computed]
    public function blockReason(): ?string
    {
        return $this->member ? IssueBooks::blockReason($this->member) : null;
    }

    #[Computed]
    public function rule(): array
    {
        return CirculationRules::rule($this->member?->member_type);
    }

    #[Computed]
    public function dueDate(): string
    {
        return CirculationRules::dueDate(today(), $this->rule['loan_days'])->format('D, d M Y');
    }

    public function addBook(): void
    {
        $this->authorizePermission('circulation.issue');
        $code = trim($this->scan);
        $this->scan = '';
        $this->resetErrorBag('scan');
        if ($code === '') {
            return;
        }

        $copy = BookCopy::with('book')->where('barcode', $code)->orWhere('accession_no', $code)->first();
        if (! $copy) {
            $this->addError('scan', "No book found for “{$code}”.");
        } elseif ($copy->status !== BookCopy::AVAILABLE) {
            $this->addError('scan', "{$copy->book->title} ({$copy->accession_no}) is ".strtolower(BookCopy::STATUSES[$copy->status] ?? $copy->status).'.');
        } elseif (collect($this->cart)->contains('barcode', $copy->barcode)) {
            $this->addError('scan', 'Already in the list.');
        } else {
            $this->cart[] = ['barcode' => $copy->barcode, 'accession' => $copy->accession_no, 'title' => $copy->book->title];
        }
        $this->dispatch('focus-scan');
    }

    public function removeBook(int $index): void
    {
        unset($this->cart[$index]);
        $this->cart = array_values($this->cart);
    }

    public function issue(): void
    {
        $this->authorizePermission('circulation.issue');
        abort_unless($this->member, 422);

        try {
            $loan = app(IssueBooks::class)($this->member, array_column($this->cart, 'barcode'), $this->notes);
        } catch (BusinessRuleException $e) {
            $this->addError('scan', $e->getMessage());

            return;
        }

        $this->issued = ['id' => $loan->id, 'no' => $loan->loan_no, 'count' => $loan->items->count(), 'due' => $loan->items->first()->due_on->format('d M Y')];
        $this->reset('cart', 'notes');
        unset($this->openItems);
        $this->dispatch('focus-scan');
    }

    public function renew(int $itemId): void
    {
        $this->authorizePermission('circulation.renew');
        $item = LoanItem::findOrFail($itemId);

        try {
            $item = app(RenewLoanItem::class)->staff($item);
            $this->dispatch('notify', message: "Renewed until {$item->due_on->format('d M Y')}.");
        } catch (BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
        unset($this->openItems);
    }

    // ---------------- return ----------------

    protected function preview(LoanItem $item): array
    {
        $rule = CirculationRules::rule($item->student->member_type);
        $late = CirculationRules::overdueDays($item->due_on, now(), $rule['grace_days']);

        return ['item' => $item->id, 'title' => $item->book->title, 'accession' => $item->copy->accession_no, 'member' => $item->student->name, 'due' => $item->due_on->format('d M Y'),
            'late' => $late, 'fine' => $late > 0 ? CirculationRules::fineFor($rule, $late) : '0.00', 'condition' => 'good', 'fee' => ''];
    }

    public function addReturn(): void
    {
        $this->authorizePermission('circulation.return');
        $code = trim($this->returnScan);
        $this->returnScan = '';
        $this->resetErrorBag('returnScan');
        if ($code === '') {
            return;
        }

        $copy = BookCopy::where('barcode', $code)->orWhere('accession_no', $code)->first();
        $item = $copy ? LoanItem::with(['book', 'copy', 'student'])->open()->where('book_copy_id', $copy->id)->first() : null;

        if (! $copy) {
            $this->addError('returnScan', "No book found for “{$code}”.");
        } elseif (! $item) {
            $this->addError('returnScan', "{$copy->accession_no} is not out on loan.");
        } elseif (collect($this->returnCart)->contains('item', $item->id)) {
            $this->addError('returnScan', 'Already in the list.');
        } else {
            $this->returnCart[] = $this->preview($item);
        }
        $this->dispatch('focus-return');
    }

    public function stageReturn(int $itemId): void
    {
        $this->authorizePermission('circulation.return');
        $item = LoanItem::with(['book', 'copy', 'student'])->open()->findOrFail($itemId);
        if (! collect($this->returnCart)->contains('item', $item->id)) {
            $this->returnCart[] = $this->preview($item);
        }
        $this->tab = 'return';
    }

    public function removeReturn(int $index): void
    {
        unset($this->returnCart[$index]);
        $this->returnCart = array_values($this->returnCart);
    }

    public function processReturn(): void
    {
        $this->authorizePermission('circulation.return');

        $this->validate(['returnCart.*.condition' => ['in:good,damaged,lost'], 'returnCart.*.fee' => ['nullable', 'numeric', 'min:0', 'max:9999999']], [], ['returnCart.*.fee' => 'damage fee']);

        try {
            $r = app(ReturnBooks::class)(array_map(fn ($row) => ['item' => $row['item'], 'condition' => $row['condition'], 'fee' => $row['fee'] ?: null], $this->returnCart));
        } catch (BusinessRuleException $e) {
            $this->addError('returnScan', $e->getMessage());

            return;
        }

        $this->returned = ['receipt' => $r['receipt_no'], 'count' => count($r['items']), 'fines' => (float) collect($r['fines'])->sum(fn ($f) => (float) $f->amount)];
        $this->reset('returnCart');
        unset($this->openItems, $this->unpaidFines);
        $this->dispatch('focus-return');
    }
}
?>

<div class="space-y-5" x-data @focus-scan.window="$nextTick(() => $refs.scan?.focus())" @focus-return.window="$nextTick(() => $refs.rscan?.focus())">
    <x-slot:header><x-ui.page-title title="Issue & return" subtitle="Scan the member, then the books" /></x-slot:header>

    @php
        $slug = \App\Support\Tenancy::slug();
        $m = $this->member;
        $canIssue = $this->can('circulation.issue');
        $canReturn = $this->can('circulation.return');
    @endphp

    <div class="flex gap-2 border-b border-gray-200">
        @if ($canIssue) <button wire:click="$set('tab', 'issue')" class="border-b-2 px-4 py-2.5 text-sm font-medium {{ $tab === 'issue' ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">Issue books</button> @endif
        @if ($canReturn) <button wire:click="$set('tab', 'return')" class="border-b-2 px-4 py-2.5 text-sm font-medium {{ $tab === 'return' ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">Return books @if (count($returnCart)) <span class="ml-1 rounded-full bg-brand-100 px-2 text-xs text-brand-700">{{ count($returnCart) }}</span> @endif</button> @endif
    </div>

    <div class="grid gap-5 xl:grid-cols-12">
        {{-- member --}}
        <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 xl:col-span-5">
            <h3 class="text-sm font-semibold text-gray-900">Member</h3>
            @if (! $m)
                <div class="relative">
                    <x-lm.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                    <input type="search" autofocus wire:model.live.debounce.250ms="memberSearch" wire:keydown.enter="findMember" placeholder="Scan student ID, or type name / phone…"
                        class="w-full rounded-lg border-gray-300 py-2 pl-9 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm {{ $this->results->isEmpty() ? 'hidden' : '' }}">
                    @foreach ($this->results as $s)
                        <li wire:key="res-{{ $s->id }}"><button wire:click="pickMember({{ $s->id }})" class="flex w-full items-center justify-between px-3 py-2 text-left hover:bg-brand-50"><span class="font-medium text-gray-900">{{ $s->name }}</span><span class="text-xs text-gray-500">{{ $s->student_code }} · {{ $s->phone }}</span></button></li>
                    @endforeach
                </ul>
                @if (mb_strlen(trim($memberSearch)) >= 2 && $this->results->isEmpty()) <p class="text-sm text-gray-400">No member found.</p> @endif
            @else
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-base font-semibold text-gray-900">{{ $m->name }}</p>
                        <p class="text-xs text-gray-500">{{ $m->student_code }} · {{ $m->phone }} · {{ \App\Support\CirculationRules::memberTypes()[$m->member_type] ?? 'Student' }}</p>
                    </div>
                    <button wire:click="clearMember" class="text-xs font-medium text-gray-500 hover:text-gray-800">Change</button>
                </div>
                @if ($this->blockReason) <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $this->blockReason }}</div> @endif
                <dl class="grid grid-cols-3 gap-3 text-center text-xs">
                    <div class="rounded-lg bg-gray-50 p-2"><dt class="text-gray-500">Borrowed</dt><dd class="text-base font-semibold text-gray-900">{{ $this->openItems->count() }} / {{ $this->rule['max_items'] }}</dd></div>
                    <div class="rounded-lg bg-gray-50 p-2"><dt class="text-gray-500">Loan period</dt><dd class="text-base font-semibold text-gray-900">{{ $this->rule['loan_days'] }} days</dd></div>
                    <div class="rounded-lg {{ $this->unpaidFines > 0 ? 'bg-red-50' : 'bg-gray-50' }} p-2"><dt class="text-gray-500">Unpaid fines</dt><dd class="text-base font-semibold {{ $this->unpaidFines > 0 ? 'text-red-600' : 'text-gray-900' }}">₹{{ number_format($this->unpaidFines, 2) }}</dd></div>
                </dl>

                <div>
                    <p class="mb-2 text-xs font-medium text-gray-500">Books with this member</p>
                    <ul class="divide-y divide-gray-100 text-sm">
                        @forelse ($this->openItems as $i)
                            @php $late = $i->due_on->lt(today()); @endphp
                            <li class="py-2" wire:key="oi-{{ $i->id }}">
                                <div class="flex items-center justify-between gap-2"><span class="truncate font-medium text-gray-900">{{ $i->book->title }}</span>
                                    <x-ui.badge :color="$late ? 'red' : ($i->due_on->isToday() ? 'amber' : 'green')">{{ $late ? 'Overdue' : 'Due '.$i->due_on->format('d M') }}</x-ui.badge></div>
                                <div class="mt-0.5 flex items-center justify-between text-xs text-gray-500">
                                    <span>{{ $i->copy->accession_no }} · due {{ $i->due_on->format('d M Y') }}@if ($i->renewals) · renewed {{ $i->renewals }}× @endif</span>
                                    <span class="flex gap-3">
                                        @if ($canReturn) <button wire:click="stageReturn({{ $i->id }})" class="font-medium text-brand-600 hover:text-brand-800">Return</button> @endif
                                        @if ($this->can('circulation.renew') && ! \App\Actions\Circulation\RenewLoanItem::blockReason($i)) <button wire:click="renew({{ $i->id }})" class="font-medium text-gray-600 hover:text-gray-900">Renew</button> @endif
                                    </span>
                                </div>
                            </li>
                        @empty
                            <li class="py-4 text-center text-xs text-gray-400">Nothing borrowed right now.</li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </section>

        {{-- work area --}}
        <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 xl:col-span-7">
            @if ($tab === 'issue' && $canIssue)
                <h3 class="text-sm font-semibold text-gray-900">Books to issue</h3>

                @if ($issued)
                    <div class="rounded-lg border border-brand-200 bg-brand-50 p-4 text-sm text-brand-800">
                        <p class="font-semibold">Issued {{ $issued['count'] }} book{{ $issued['count'] === 1 ? '' : 's' }} · {{ $issued['no'] }}</p>
                        <p class="mt-0.5">Due {{ $issued['due'] }}. <a href="{{ route('app.circulation.receipt.issue', ['library' => $slug, 'loan' => $issued['id']]) }}" target="_blank" class="font-semibold underline">Print issue slip</a></p>
                    </div>
                @endif

                @if (! $m)
                    <p class="rounded-lg bg-gray-50 p-6 text-center text-sm text-gray-500">Choose a member first.</p>
                @else
                    <div>
                        <input type="text" x-ref="scan" wire:model="scan" wire:keydown.enter="addBook" placeholder="Scan the book barcode (or type the accession number) and press Enter" @disabled($this->blockReason)
                            class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 disabled:bg-gray-50">
                        @error('scan') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    @if ($cart)
                        <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm">
                            @foreach ($cart as $i => $row)
                                <li class="flex items-center justify-between px-3 py-2" wire:key="cart-{{ $row['barcode'] }}"><span><span class="font-medium text-gray-900">{{ $row['title'] }}</span> <span class="text-xs text-gray-500">{{ $row['accession'] }}</span></span>
                                    <button wire:click="removeBook({{ $i }})" class="text-xs font-medium text-red-600 hover:text-red-800">Remove</button></li>
                            @endforeach
                        </ul>
                        <p class="text-xs text-gray-500">All will be due on <strong>{{ $this->dueDate }}</strong>.</p>
                        <x-ui.textarea name="notes" label="Note (optional)" wire:model="notes" rows="2" />
                        <x-ui.button wire:click="issue" wire:loading.attr="disabled" wire:target="issue">Issue {{ count($cart) }} book{{ count($cart) === 1 ? '' : 's' }}</x-ui.button>
                    @endif
                @endif
            @elseif ($canReturn)
                <h3 class="text-sm font-semibold text-gray-900">Books coming back</h3>

                @if ($returned)
                    <div class="rounded-lg border border-brand-200 bg-brand-50 p-4 text-sm text-brand-800">
                        <p class="font-semibold">Returned {{ $returned['count'] }} book{{ $returned['count'] === 1 ? '' : 's' }} · {{ $returned['receipt'] }}</p>
                        <p class="mt-0.5">@if ($returned['fines'] > 0) Fines raised: <strong>₹{{ number_format($returned['fines'], 2) }}</strong>. @endif
                            <a href="{{ route('app.circulation.receipt.return', ['library' => $slug, 'receipt' => $returned['receipt']]) }}" target="_blank" class="font-semibold underline">Print receipt</a>
                            @if ($returned['fines'] > 0 && $this->can('fines.view')) · <a href="{{ route('app.fines.index', ['library' => $slug]) }}" wire:navigate class="font-semibold underline">Collect fines</a> @endif</p>
                    </div>
                @endif

                <div>
                    <input type="text" x-ref="rscan" wire:model="returnScan" wire:keydown.enter="addReturn" placeholder="Scan the returned book's barcode and press Enter"
                        class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('returnScan') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                @if ($returnCart)
                    <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm">
                        @foreach ($returnCart as $i => $row)
                            <li class="space-y-2 px-3 py-2.5" wire:key="rc-{{ $row['item'] }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div><p class="font-medium text-gray-900">{{ $row['title'] }} <span class="text-xs font-normal text-gray-500">{{ $row['accession'] }}</span></p>
                                        <p class="text-xs text-gray-500">{{ $row['member'] }} · due {{ $row['due'] }}@if ($row['late'] > 0) · <span class="font-semibold text-red-600">{{ $row['late'] }} day(s) late, fine ₹{{ number_format((float) $row['fine'], 2) }}</span>@endif</p></div>
                                    <button wire:click="removeReturn({{ $i }})" class="text-xs font-medium text-red-600 hover:text-red-800">Remove</button>
                                </div>
                                <div class="flex flex-wrap items-center gap-3 text-xs">
                                    <label class="flex items-center gap-1.5 text-gray-600">Condition
                                        <select wire:model.live="returnCart.{{ $i }}.condition" @disabled(! $this->can('circulation.manage')) class="rounded-md border-gray-300 py-1 text-xs focus:border-brand-500 focus:ring-brand-500">
                                            <option value="good">Good</option>@if ($this->can('circulation.manage'))<option value="damaged">Damaged</option><option value="lost">Lost</option>@endif
                                        </select></label>
                                    @if ($row['condition'] === 'damaged')
                                        <label class="flex items-center gap-1.5 text-gray-600">Damage fee ₹ <input type="number" step="0.01" min="0" wire:model="returnCart.{{ $i }}.fee" class="w-24 rounded-md border-gray-300 py-1 text-xs focus:border-brand-500 focus:ring-brand-500"></label>
                                    @endif
                                    @if ($row['condition'] === 'lost') <span class="text-red-600">A lost-book fine is added from the book's price.</span> @endif
                                    @error("returnCart.$i.fee") <span class="text-red-600">{{ $message }}</span> @enderror
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <x-ui.button wire:click="processReturn" wire:loading.attr="disabled" wire:target="processReturn">Return {{ count($returnCart) }} book{{ count($returnCart) === 1 ? '' : 's' }}</x-ui.button>
                @else
                    <p class="rounded-lg bg-gray-50 p-6 text-center text-sm text-gray-500">Scan a book to start. You can return several at once.</p>
                @endif
            @endif
        </section>
    </div>
</div>
