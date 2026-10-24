<?php

use App\Actions\Circulation\Reservations;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Book;
use App\Models\Reservation;
use App\Models\Student;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithPagination;

    #[Url]
    public string $status = 'active';        // active | ready | waiting | closed | all

    public bool $showNew = false;
    public string $memberQuery = '';
    public ?int $memberId = null;
    public string $titleQuery = '';

    public function mount(): void
    {
        $this->authorizePermission('circulation.view');
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function rows()
    {
        return Reservation::with(['student', 'book', 'copy'])
            ->when($this->status === 'active', fn ($q) => $q->active())
            ->when(in_array($this->status, ['ready', 'waiting'], true), fn ($q) => $q->where('status', $this->status))
            ->when($this->status === 'closed', fn ($q) => $q->whereNotIn('status', ['waiting', 'ready']))
            ->orderByRaw("status = 'ready' desc")->orderBy('requested_at')->orderBy('id')->paginate(20);
    }

    /** Place in the queue for a waiting reservation (1 = next). */
    public function position(Reservation $r): int
    {
        return Reservation::where('book_id', $r->book_id)->where('status', Reservation::WAITING)
            ->where(fn ($q) => $q->where('requested_at', '<', $r->requested_at)->orWhere(fn ($w) => $w->where('requested_at', $r->requested_at)->where('id', '<=', $r->id)))->count();
    }

    public function cancel(int $id): void
    {
        $this->authorizePermission('circulation.issue');

        try {
            app(Reservations::class)->cancel(Reservation::findOrFail($id));
            $this->dispatch('notify', message: 'Reservation cancelled.');
        } catch (BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
        unset($this->rows);
    }

    // ---- new reservation on a member's behalf ----
    #[Computed]
    public function memberResults()
    {
        $q = trim($this->memberQuery);

        return mb_strlen($q) < 2 || $this->memberId ? collect()
            : Student::where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('student_code', 'like', "%{$q}%"))->orderBy('name')->limit(5)->get();
    }

    #[Computed]
    public function member(): ?Student
    {
        return $this->memberId ? Student::find($this->memberId) : null;
    }

    #[Computed]
    public function titleResults()
    {
        $q = trim($this->titleQuery);

        return mb_strlen($q) < 2 ? collect()
            : Book::where('type', Book::PHYSICAL)->where('status', 'active')->search($q)
                ->withCount(['copies as available' => fn ($c) => $c->where('status', 'available')])->orderBy('title')->limit(6)->get();
    }

    public function openNew(): void
    {
        $this->authorizePermission('circulation.issue');
        $this->reset('memberQuery', 'memberId', 'titleQuery');
        $this->resetErrorBag();
        $this->showNew = true;
    }

    public function pickMember(int $id): void
    {
        $this->memberId = Student::findOrFail($id)->id;
        $this->memberQuery = '';
    }

    public function reserve(int $bookId): void
    {
        $this->authorizePermission('circulation.issue');
        abort_unless($this->member, 422);

        try {
            app(Reservations::class)->staff($this->member, Book::findOrFail($bookId));
        } catch (BusinessRuleException $e) {
            $this->addError('titleQuery', $e->getMessage());

            return;
        }

        $this->showNew = false;
        unset($this->rows);
        $this->dispatch('notify', message: 'Reserved.');
    }

    public function closeModal(): void
    {
        $this->showNew = false;
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Reservations" subtitle="Members waiting for a book" /></x-slot:header>

    @php $slug = \App\Support\Tenancy::slug(); @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            @foreach (['active' => 'Open', 'ready' => 'Ready to collect', 'waiting' => 'Waiting', 'closed' => 'Closed', 'all' => 'All'] as $key => $label)
                <button wire:click="$set('status', '{{ $key }}')" wire:key="rs-{{ $key }}" class="rounded-lg border px-3.5 py-1.5 text-sm font-medium {{ $status === $key ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50' }}">{{ $label }}</button>
            @endforeach
        </div>
        @if ($this->can('circulation.issue') && \App\Support\LibrarySettings::get('reservations_enabled'))
            <x-ui.button wire:click="openNew"><x-lm.icon name="plus" class="h-4 w-4" /> New reservation</x-ui.button>
        @endif
    </div>

    @unless (\App\Support\LibrarySettings::get('reservations_enabled'))
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">Reservations are switched off in Settings → Books &amp; e-books.</div>
    @endunless

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left text-xs text-gray-500">
                <th class="px-4 py-3 font-medium">Book</th><th class="px-4 py-3 font-medium">Member</th><th class="px-4 py-3 font-medium">Reserved on</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th>
            </tr></thead>
            <tbody>
                @forelse ($this->rows as $r)
                    <tr class="border-t border-gray-100" wire:key="rv-{{ $r->id }}">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->book->title }}</td>
                        <td class="px-4 py-3"><p class="text-gray-900">{{ $r->student->name }}</p><p class="text-xs text-gray-500">{{ $r->student->student_code }}</p></td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->requested_at->format('d M Y') }}</td>
                        <td class="px-4 py-3">
                            @if ($r->status === 'ready') <x-ui.badge color="green">Ready</x-ui.badge><p class="mt-0.5 text-xs text-gray-500">{{ $r->copy?->accession_no }} · collect by {{ $r->expires_on?->format('d M') }}</p>
                            @elseif ($r->status === 'waiting') <x-ui.badge color="amber">Waiting · #{{ $this->position($r) }} in line</x-ui.badge>
                            @else <x-ui.badge :color="$r->status === 'fulfilled' ? 'blue' : 'gray'">{{ ucfirst($r->status) }}</x-ui.badge><p class="mt-0.5 text-xs text-gray-400">{{ $r->closed_reason }}</p> @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            @if ($r->status === 'ready' && $this->can('circulation.issue')) <a href="{{ route('app.circulation.index', ['library' => $slug, 'member' => $r->student_id]) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-800">Issue</a> @endif
                            @if ($r->isActive() && $this->can('circulation.issue')) <button wire:click="cancel({{ $r->id }})" wire:confirm="Cancel this reservation?" class="ml-3 font-medium text-red-600 hover:text-red-800">Cancel</button> @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-gray-400">No reservations.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->rows->links() }}

    @if ($showNew)
        <x-ui.modal title="New reservation" max="max-w-lg" wire:key="newres-modal">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Member</label>
                    @if ($this->member)
                        <div class="mt-1 flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-sm"><span class="font-medium text-gray-900">{{ $this->member->name }} <span class="text-xs font-normal text-gray-500">{{ $this->member->student_code }}</span></span><button wire:click="$set('memberId', null)" class="text-xs font-medium text-gray-500 hover:text-gray-800">Change</button></div>
                    @else
                        <input type="search" wire:model.live.debounce.250ms="memberQuery" placeholder="Name, phone or student ID…" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                        <ul class="mt-1 divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm {{ $this->memberResults->isEmpty() ? 'hidden' : '' }}">
                            @foreach ($this->memberResults as $s) <li wire:key="nm-{{ $s->id }}"><button wire:click="pickMember({{ $s->id }})" class="flex w-full justify-between px-3 py-2 text-left hover:bg-brand-50"><span>{{ $s->name }}</span><span class="text-xs text-gray-500">{{ $s->student_code }}</span></button></li> @endforeach
                        </ul>
                    @endif
                </div>
                @if ($this->member)
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Book</label>
                        <input type="search" wire:model.live.debounce.250ms="titleQuery" placeholder="Title, author or ISBN…" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                        @error('titleQuery') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        <ul class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm {{ $this->titleResults->isEmpty() ? 'hidden' : '' }}">
                            @foreach ($this->titleResults as $b)
                                <li class="flex items-center justify-between gap-3 px-3 py-2" wire:key="nt-{{ $b->id }}">
                                    <span class="min-w-0"><span class="block truncate font-medium text-gray-900">{{ $b->title }}</span><span class="text-xs {{ $b->available > 0 ? 'text-brand-700' : 'text-red-600' }}">{{ $b->available > 0 ? $b->available.' on the shelf' : 'None on the shelf' }}</span></span>
                                    <x-ui.button wire:click="reserve({{ $b->id }})" wire:key="nb-{{ $b->id }}">Reserve</x-ui.button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <div class="flex justify-end"><x-ui.button variant="secondary" wire:click="closeModal">Close</x-ui.button></div>
            </div>
        </x-ui.modal>
    @endif
</div>
