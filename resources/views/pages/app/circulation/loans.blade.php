<?php

use App\Actions\Circulation\RenewLoanItem;
use App\Actions\Circulation\ReverseCirculation;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\LoanItem;
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
    public string $status = 'open';        // open | overdue | due_soon | returned | all

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    public ?int $reversing = null;
    public string $reverseKind = '';       // issue | return
    public string $reason = '';

    public function mount(): void
    {
        $this->authorizePermission('circulation.view');
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['status', 'search', 'from', 'until'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'open' => LoanItem::open()->count(),
            'overdue' => LoanItem::overdue()->count(),
            'due_soon' => LoanItem::open()->whereBetween('due_on', [today()->toDateString(), today()->addDays(3)->toDateString()])->count(),
        ];
    }

    #[Computed]
    public function rows()
    {
        return LoanItem::with(['book', 'copy', 'student', 'loan'])
            ->when($this->status === 'open', fn ($q) => $q->open())
            ->when($this->status === 'overdue', fn ($q) => $q->overdue())
            ->when($this->status === 'due_soon', fn ($q) => $q->open()->whereBetween('due_on', [today()->toDateString(), today()->addDays(3)->toDateString()]))
            ->when($this->status === 'returned', fn ($q) => $q->whereIn('status', [LoanItem::RETURNED, LoanItem::LOST]))
            ->when(trim($this->search) !== '', function ($q) {
                $t = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w
                    ->whereHas('student', fn ($s) => $s->where('name', 'like', $t)->orWhere('student_code', 'like', $t)->orWhere('phone', 'like', $t))
                    ->orWhereHas('book', fn ($b) => $b->where('title', 'like', $t)->orWhere('isbn', 'like', $t))
                    ->orWhereHas('copy', fn ($c) => $c->where('accession_no', 'like', $t))
                    ->orWhereHas('loan', fn ($l) => $l->where('loan_no', 'like', $t)));
            })
            ->when($this->from !== '', fn ($q) => $q->whereDate('issued_at', '>=', $this->from))
            ->when($this->until !== '', fn ($q) => $q->whereDate('issued_at', '<=', $this->until))
            ->orderByRaw("status = 'issued' desc")->orderBy($this->status === 'returned' ? 'returned_at' : 'due_on', $this->status === 'returned' ? 'desc' : 'asc')->paginate(20);
    }

    public function renew(int $id): void
    {
        $this->authorizePermission('circulation.renew');

        try {
            $item = app(RenewLoanItem::class)->staff(LoanItem::findOrFail($id));
            $this->dispatch('notify', message: "Renewed until {$item->due_on->format('d M Y')}.");
        } catch (BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
        unset($this->rows);
    }

    public function startReverse(int $id, string $kind): void
    {
        $this->authorizePermission('circulation.manage');
        abort_unless(in_array($kind, ['issue', 'return'], true), 422);
        LoanItem::findOrFail($id);

        $this->reversing = $id;
        $this->reverseKind = $kind;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function reverse(): void
    {
        $this->authorizePermission('circulation.manage');
        $this->validate(['reason' => ['required', 'string', 'max:500']]);
        $item = LoanItem::findOrFail($this->reversing);

        try {
            $this->reverseKind === 'issue' ? app(ReverseCirculation::class)->issue($item, $this->reason) : app(ReverseCirculation::class)->return($item, $this->reason);
        } catch (BusinessRuleException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }

        $this->closeModal();
        unset($this->rows, $this->counts);
        $this->dispatch('notify', message: 'Reversed.');
    }

    public function closeModal(): void
    {
        $this->reversing = null;
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Loans" subtitle="Everything issued and returned" /></x-slot:header>

    @php
        $slug = \App\Support\Tenancy::slug();
        $c = $this->counts;
        $tabs = ['open' => ['Out now', $c['open']], 'overdue' => ['Overdue', $c['overdue']], 'due_soon' => ['Due in 3 days', $c['due_soon']], 'returned' => ['Returned', null], 'all' => ['All', null]];
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            @foreach ($tabs as $key => [$label, $n])
                <button wire:click="$set('status', '{{ $key }}')" wire:key="lt-{{ $key }}" class="rounded-lg border px-3.5 py-1.5 text-sm font-medium {{ $status === $key ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50' }}">
                    {{ $label }}@if ($n !== null) <span class="ml-1 {{ $key === 'overdue' && $n > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ $n }}</span> @endif
                </button>
            @endforeach
        </div>
        @if ($this->can('circulation.issue') || $this->can('circulation.return'))
            <a href="{{ route('app.circulation.index', ['library' => $slug]) }}" wire:navigate class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Issue & return</a>
        @endif
    </div>

    <div class="flex flex-wrap gap-3">
        <div class="relative min-w-64 flex-1">
            <x-lm.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Member, book title, ISBN, accession or loan no…" class="w-full rounded-lg border-gray-300 py-2 pl-9 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
        <input type="date" wire:model.live="from" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500" aria-label="Issued from">
        <input type="date" wire:model.live="until" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500" aria-label="Issued until">
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left text-xs text-gray-500">
                <th class="px-4 py-3 font-medium">Loan</th><th class="px-4 py-3 font-medium">Member</th><th class="px-4 py-3 font-medium">Book</th>
                <th class="px-4 py-3 font-medium">Issued</th><th class="px-4 py-3 font-medium">Due / returned</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th>
            </tr></thead>
            <tbody>
                @forelse ($this->rows as $i)
                    @php $late = $i->isOverdue(); @endphp
                    <tr class="border-t border-gray-100" wire:key="li-{{ $i->id }}">
                        <td class="px-4 py-3 text-gray-500"><a href="{{ route('app.circulation.receipt.issue', ['library' => $slug, 'loan' => $i->loan_id]) }}" target="_blank" class="hover:text-brand-700">{{ $i->loan->loan_no }}</a></td>
                        <td class="px-4 py-3"><p class="font-medium text-gray-900">{{ $i->student->name }}</p><p class="text-xs text-gray-500">{{ $i->student->student_code }}</p></td>
                        <td class="px-4 py-3"><p class="max-w-56 truncate text-gray-900">{{ $i->book->title }}</p><p class="text-xs text-gray-500">{{ $i->copy->accession_no }}</p></td>
                        <td class="px-4 py-3 text-gray-600">{{ $i->issued_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 {{ $late ? 'font-semibold text-red-600' : 'text-gray-600' }}">
                            @if ($i->isOpen()) {{ $i->due_on->format('d M Y') }}@if ($late) <span class="block text-xs font-normal">{{ $i->lateDays() }} day(s) late</span>@endif
                            @else {{ $i->returned_at?->format('d M Y') ?? '—' }} @endif
                        </td>
                        <td class="px-4 py-3">
                            @switch($i->status)
                                @case('issued') <x-ui.badge :color="$late ? 'red' : 'blue'">{{ $late ? 'Overdue' : 'Issued' }}{{ $i->renewals ? ' · renewed '.$i->renewals.'×' : '' }}</x-ui.badge> @break
                                @case('returned') <x-ui.badge color="green">Returned{{ $i->return_condition === 'damaged' ? ' · damaged' : '' }}</x-ui.badge> @break
                                @case('lost') <x-ui.badge color="red">Lost</x-ui.badge> @break
                                @default <x-ui.badge color="gray">Reversed</x-ui.badge>
                            @endswitch
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            @if ($i->isOpen())
                                @if ($this->can('circulation.return')) <a href="{{ route('app.circulation.index', ['library' => $slug, 'tab' => 'return', 'member' => $i->student_id]) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-800">Return</a> @endif
                                @if ($this->can('circulation.renew') && ! \App\Actions\Circulation\RenewLoanItem::blockReason($i)) <button wire:click="renew({{ $i->id }})" class="ml-3 font-medium text-gray-600 hover:text-gray-900">Renew</button> @endif
                                @if ($this->can('circulation.manage')) <button wire:click="startReverse({{ $i->id }}, 'issue')" class="ml-3 font-medium text-amber-600 hover:text-amber-800">Reverse issue</button> @endif
                            @elseif (in_array($i->status, ['returned', 'lost'], true))
                                @if ($i->return_receipt_no) <a href="{{ route('app.circulation.receipt.return', ['library' => $slug, 'receipt' => $i->return_receipt_no]) }}" target="_blank" class="font-medium text-gray-600 hover:text-gray-900">Receipt</a> @endif
                                @if ($this->can('circulation.manage')) <button wire:click="startReverse({{ $i->id }}, 'return')" class="ml-3 font-medium text-amber-600 hover:text-amber-800">Reverse return</button> @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-400">{{ $search !== '' ? 'Nothing matches your search.' : 'Nothing here.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->rows->links() }}

    @if ($reversing)
        <x-ui.modal :title="$reverseKind === 'issue' ? 'Reverse this issue' : 'Reverse this return'" max="max-w-md" wire:key="rev-modal">
            <form wire:submit="reverse" class="space-y-4">
                <p class="text-sm text-gray-700">{{ $reverseKind === 'issue' ? 'Use this for a book issued by mistake. The copy goes back on the shelf.' : 'Use this for a book returned by mistake. It goes back to the member, and unpaid fines from that return are removed.' }}</p>
                <x-ui.textarea name="reason" label="Reason (kept in the history)" required wire:model="reason" rows="3" />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit" variant="warning">Reverse</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
