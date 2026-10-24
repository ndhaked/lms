<?php

use App\Actions\Books\AddCopies;
use App\Actions\Books\ChangeCopyStatus;
use App\Actions\Books\ManageBookLifecycle;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\EbookAccessLog;
use App\Support\Entitlements;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions;

    public int $bookId;

    // add copies
    public bool $showAdd = false;
    public string $count = '1';
    public string $accessionNo = '';
    public string $price = '';
    public string $supplier = '';
    public string $purchasedOn = '';
    public string $copyNote = '';

    // change status / history
    public ?int $statusFor = null;
    public string $statusTo = '';
    public string $statusNote = '';
    public ?int $historyFor = null;

    public bool $confirmDelete = false;

    public function mount(string $book): void
    {
        $model = Book::findOrFail($book);   // tenant-scoped: another library's id is a 404
        $this->bookId = $model->id;

        abort_unless(
            Entitlements::hasModule($model->isEbook() ? 'ebooks' : 'books') && $this->can($model->isEbook() ? 'ebooks.view' : 'books.view'),
            403,
        );
    }

    #[Computed]
    public function book(): Book
    {
        return Book::with(['category.parent'])->findOrFail($this->bookId);
    }

    #[Computed]
    public function copies()
    {
        return BookCopy::where('book_id', $this->bookId)->orderBy('accession_no')->get();
    }

    #[Computed]
    public function usage(): array
    {
        $logs = EbookAccessLog::where('book_id', $this->bookId);

        return [
            'total' => (clone $logs)->count(),
            'last30' => (clone $logs)->where('created_at', '>=', now()->subDays(30))->count(),
            'readers' => (clone $logs)->whereNotNull('user_id')->distinct()->count('user_id'),
        ];
    }

    #[Computed]
    public function recentAccess()
    {
        return EbookAccessLog::where('book_id', $this->bookId)->latest('id')->limit(8)->get()->map(function ($log) {
            $log->person = \App\Models\User::find($log->user_id)?->name ?? '—';

            return $log;
        });
    }

    #[Computed]
    public function history()
    {
        return $this->historyFor ? BookCopy::with(['events.user'])->find($this->historyFor) : null;
    }

    protected function canManageCopies(): bool
    {
        return ! $this->book->isEbook() && $this->can('books.copies');
    }

    // ---- add copies ----
    public function openAdd(): void
    {
        $this->authorizePermission('books.copies');
        $this->reset('count', 'accessionNo', 'copyNote');
        $this->price = (string) ($this->book->purchase_price ?? '');
        $this->supplier = (string) ($this->book->supplier ?? '');
        $this->purchasedOn = $this->book->purchased_on?->toDateString() ?? today()->toDateString();
        $this->resetErrorBag();
        $this->showAdd = true;
    }

    public function saveAdd(): void
    {
        $this->authorizePermission('books.copies');
        $this->validate([
            'count' => ['required', 'integer', 'min:1', 'max:'.AddCopies::MAX_AT_ONCE],
            'accessionNo' => ['nullable', 'string', 'max:40'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'purchasedOn' => ['nullable', 'date', 'before_or_equal:today'],
            'copyNote' => ['nullable', 'string', 'max:255'],
        ], [], ['count' => 'number of copies', 'accessionNo' => 'accession number']);

        try {
            $made = app(AddCopies::class)($this->book, (int) $this->count, [
                'purchase_price' => $this->price, 'supplier' => $this->supplier, 'purchased_on' => $this->purchasedOn ?: null, 'note' => $this->copyNote,
            ], $this->accessionNo ?: null);
        } catch (BusinessRuleException $e) {
            $this->addError('count', $e->getMessage());

            return;
        }

        $this->showAdd = false;
        unset($this->copies);
        $this->dispatch('notify', message: $made->count() === 1 ? "Copy {$made->first()->accession_no} added." : "{$made->count()} copies added ({$made->first()->accession_no} – {$made->last()->accession_no}).");
    }

    // ---- change status ----
    public function openStatus(int $copyId): void
    {
        $this->authorizePermission('books.copies');
        $copy = BookCopy::where('book_id', $this->bookId)->findOrFail($copyId);
        $this->statusFor = $copy->id;
        $this->statusTo = '';
        $this->statusNote = '';
        $this->resetErrorBag();
    }

    public function saveStatus(): void
    {
        $this->authorizePermission('books.copies');
        $this->validate(['statusTo' => ['required', 'in:'.implode(',', array_keys(BookCopy::STATUSES))], 'statusNote' => ['nullable', 'string', 'max:500']], ['statusTo.required' => 'Choose the new status.'], ['statusTo' => 'status']);

        $copy = BookCopy::where('book_id', $this->bookId)->findOrFail($this->statusFor);

        try {
            app(ChangeCopyStatus::class)($copy, $this->statusTo, $this->statusNote);
        } catch (BusinessRuleException $e) {
            $this->addError('statusNote', $e->getMessage());

            return;
        }

        $this->statusFor = null;
        unset($this->copies);
        $this->dispatch('notify', message: 'Status updated.');
    }

    public function openHistory(int $copyId): void
    {
        BookCopy::where('book_id', $this->bookId)->findOrFail($copyId);
        $this->historyFor = $copyId;
    }

    // ---- title lifecycle ----
    public function archive(): void
    {
        $this->authorizePermission($this->book->isEbook() ? 'ebooks.manage' : 'books.manage');
        try {
            app(ManageBookLifecycle::class)->archive($this->book);
            $this->dispatch('notify', message: 'Archived.');
        } catch (BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
        unset($this->book);
    }

    public function restore(): void
    {
        $this->authorizePermission($this->book->isEbook() ? 'ebooks.manage' : 'books.manage');
        app(ManageBookLifecycle::class)->restore($this->book);
        unset($this->book);
        $this->dispatch('notify', message: 'Restored.');
    }

    public function delete()
    {
        $this->authorizePermission($this->book->isEbook() ? 'ebooks.manage' : 'books.manage');
        try {
            app(ManageBookLifecycle::class)->delete($this->book);
        } catch (BusinessRuleException $e) {
            $this->confirmDelete = false;
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        session()->flash('notice', 'Deleted.');

        return $this->redirect(route('app.books.index', ['library' => Tenancy::slug()]), navigate: true);
    }

    public function closeModal(): void
    {
        $this->showAdd = false;
        $this->statusFor = null;
        $this->historyFor = null;
        $this->confirmDelete = false;
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title :title="$this->book->title" subtitle="Book details" /></x-slot:header>

    @php
        $b = $this->book;
        $slug = \App\Support\Tenancy::slug();
        $manage = $this->can($b->isEbook() ? 'ebooks.manage' : 'books.manage');
        $statusColor = fn ($s) => match ($s) { 'available' => 'green', 'issued' => 'blue', 'reserved' => 'purple', 'damaged' => 'amber', 'lost' => 'red', default => 'gray' };
    @endphp

    @if (session('notice')) <div class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-700">{{ session('notice') }}</div> @endif

    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('app.books.index', ['library' => $slug]) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-800">← All books</a>
        <span class="flex-1"></span>
        @if ($b->isEbook() && ($b->hasFile() || $b->external_url) && \App\Support\Entitlements::hasModule('ebooks'))
            <a href="{{ route('app.ebooks.open', ['library' => $slug, 'book' => $b->id, 'action' => 'view']) }}" target="_blank" class="rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">Open</a>
        @endif
        @if ($manage)
            <a href="{{ route('app.books.edit', ['library' => $slug, 'book' => $b->id]) }}" wire:navigate class="rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Edit</a>
            @if ($b->isArchived())
                <x-ui.button variant="secondary" wire:click="restore">Restore</x-ui.button>
            @else
                <x-ui.button variant="warning" wire:click="archive" wire:confirm="Archive this title? Its history is kept.">Archive</x-ui.button>
            @endif
            @if (\App\Actions\Books\ManageBookLifecycle::canDelete($b)) <x-ui.button variant="danger" wire:click="$set('confirmDelete', true)">Delete</x-ui.button> @endif
        @endif
    </div>

    <section class="rounded-xl border border-gray-200 bg-white p-5">
        <div class="flex flex-col gap-5 sm:flex-row">
            @if ($b->cover_path)
                <img src="{{ route('app.books.cover', ['library' => $slug, 'book' => $b->id]) }}" alt="" class="h-48 w-32 shrink-0 rounded-lg object-cover shadow">
            @else
                <span class="flex h-48 w-32 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-500"><x-lm.icon name="{{ $b->isEbook() ? 'tablet' : 'book' }}" class="h-10 w-10" /></span>
            @endif
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-xl font-semibold text-gray-900">{{ $b->title }}</h2>
                    <x-ui.badge :color="$b->isEbook() ? 'purple' : 'blue'">{{ \App\Models\Book::TYPES[$b->type] }}</x-ui.badge>
                    @if ($b->isArchived()) <x-ui.badge color="gray">Archived</x-ui.badge> @endif
                </div>
                @if ($b->subtitle) <p class="text-sm text-gray-500">{{ $b->subtitle }}</p> @endif
                <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                    @foreach ([['Author', $b->author], ['Publisher', $b->publisher], ['Edition', $b->edition], ['Language', $b->language], ['Year', $b->publication_year], ['ISBN', $b->isbn], ['Category', $b->category?->fullName()]] as [$k, $v])
                        <div><dt class="text-xs text-gray-500">{{ $k }}</dt><dd class="font-medium text-gray-900">{{ $v ?: '—' }}</dd></div>
                    @endforeach
                    @if (! $b->isEbook())
                        <div><dt class="text-xs text-gray-500">Kept at</dt><dd class="font-medium text-gray-900">{{ $b->locationText() ?: '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-500">Purchase</dt><dd class="font-medium text-gray-900">{{ $b->purchase_price ? '₹'.number_format((float) $b->purchase_price, 2) : '—' }}{{ $b->supplier ? ' · '.$b->supplier : '' }}</dd></div>
                    @endif
                </dl>
                @if ($b->tags) <div class="mt-3 flex flex-wrap gap-1.5">@foreach ($b->tags as $t) <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">{{ $t }}</span> @endforeach</div> @endif
                @if ($b->description) <p class="mt-4 whitespace-pre-line text-sm text-gray-700">{{ $b->description }}</p> @endif
            </div>
        </div>
    </section>

    @if ($b->isEbook())
        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-3 text-sm font-semibold text-gray-900">File &amp; licence</h3>
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
                <div><dt class="text-xs text-gray-500">File</dt><dd class="font-medium text-gray-900">{{ $b->hasFile() ? $b->ebook_name.' ('.number_format($b->ebook_size / 1048576, 1).' MB)' : ($b->external_url ? 'Link only' : '—') }}</dd></div>
                <div><dt class="text-xs text-gray-500">Download</dt><dd class="font-medium text-gray-900">{{ $b->allow_download ? 'Allowed' : 'Read online only' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Licence</dt><dd class="font-medium {{ $b->licenceExpired() ? 'text-red-600' : 'text-gray-900' }}">{{ $b->licence_expires_on ? ($b->licenceExpired() ? 'Ended ' : 'Until ').$b->licence_expires_on->format('d M Y') : 'No limit' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Opened</dt><dd class="font-medium text-gray-900">{{ number_format($this->usage['total']) }} times · {{ number_format($this->usage['last30']) }} in 30 days · {{ $this->usage['readers'] }} readers</dd></div>
            </dl>
            @if ($this->recentAccess->isNotEmpty())
                <ul class="mt-4 divide-y divide-gray-100 border-t border-gray-100 text-xs text-gray-600">
                    @foreach ($this->recentAccess as $log) <li class="flex justify-between py-1.5" wire:key="log-{{ $log->id }}"><span>{{ $log->person }} · {{ $log->action === 'download' ? 'downloaded' : 'opened' }}</span><span class="text-gray-400">{{ $log->created_at->format('d M Y H:i') }}</span></li> @endforeach
                </ul>
            @endif
        </section>
    @else
        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-gray-900">Copies <span class="font-normal text-gray-400">({{ $this->copies->count() }})</span></h3>
                <div class="flex gap-2">
                    @if ($this->canManageCopies() && ! $b->isArchived())
                        <x-ui.button wire:click="openAdd"><x-lm.icon name="plus" class="h-4 w-4" /> Add copies</x-ui.button>
                    @endif
                    @if ($this->canManageCopies() && $this->copies->isNotEmpty())
                        <a href="{{ route('app.books.labels', ['library' => $slug, 'book' => $b->id]) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"><x-lm.icon name="printer" class="h-4 w-4" /> Print labels</a>
                    @endif
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="pb-2 font-medium">Accession no.</th><th class="pb-2 font-medium">Barcode</th><th class="pb-2 font-medium">Status</th><th class="pb-2 font-medium">Purchased</th><th class="pb-2 font-medium">Note</th><th class="pb-2"></th></tr></thead>
                    <tbody>
                        @forelse ($this->copies as $c)
                            <tr class="border-t border-gray-100" wire:key="c-{{ $c->id }}">
                                <td class="py-2 font-medium text-gray-900">{{ $c->accession_no }}</td>
                                <td class="py-2 text-gray-600">{{ $c->barcode }}</td>
                                <td class="py-2"><x-ui.badge :color="$statusColor($c->status)">{{ \App\Models\BookCopy::STATUSES[$c->status] }}</x-ui.badge></td>
                                <td class="py-2 text-gray-600">{{ $c->purchased_on?->format('d M Y') ?? '—' }}{{ $c->purchase_price ? ' · ₹'.number_format((float) $c->purchase_price) : '' }}</td>
                                <td class="max-w-48 truncate py-2 text-xs text-gray-500">{{ $c->note }}</td>
                                <td class="whitespace-nowrap py-2 text-right">
                                    <button wire:click="openHistory({{ $c->id }})" class="font-medium text-gray-600 hover:text-gray-900">History</button>
                                    @if ($this->canManageCopies() && ! in_array($c->status, ['issued', 'withdrawn'], true))
                                        <button wire:click="openStatus({{ $c->id }})" class="ml-3 font-medium text-brand-600 hover:text-brand-800">Change status</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-gray-400">No copies yet. Add copies to make this book available.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($showAdd)
        <x-ui.modal title="Add copies" max="max-w-lg" wire:key="add-modal">
            <form wire:submit="saveAdd" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="count" label="Number of copies" type="number" min="1" max="500" required wire:model="count" hint="Accession numbers are made automatically." />
                    <x-ui.input name="accessionNo" label="Accession number (optional)" wire:model="accessionNo" hint="Only for one copy, e.g. from an old register." />
                    <x-ui.input name="price" label="Price per copy (₹)" type="number" step="0.01" min="0" wire:model="price" />
                    <x-ui.input name="supplier" label="Supplier" wire:model="supplier" />
                    <x-ui.input name="purchasedOn" label="Purchase date" type="date" wire:model="purchasedOn" />
                    <x-ui.input name="copyNote" label="Note" wire:model="copyNote" />
                </div>
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Add</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    @if ($statusFor)
        @php $copy = $this->copies->firstWhere('id', $statusFor); $targets = \App\Actions\Books\ChangeCopyStatus::TRANSITIONS[$copy->status] ?? []; @endphp
        <x-ui.modal :title="'Change status of '.$copy->accession_no" max="max-w-md" wire:key="status-modal">
            <form wire:submit="saveStatus" class="space-y-4">
                <p class="text-sm text-gray-600">Now: <strong>{{ \App\Models\BookCopy::STATUSES[$copy->status] }}</strong></p>
                <x-ui.select name="statusTo" label="Change to" required wire:model="statusTo">
                    <option value="">Choose…</option>
                    @foreach ($targets as $t) <option value="{{ $t }}">{{ $t === 'available' ? 'Available again' : \App\Models\BookCopy::STATUSES[$t] }}</option> @endforeach
                </x-ui.select>
                <x-ui.textarea name="statusNote" label="Reason (required for damaged, lost or withdrawn)" wire:model="statusNote" rows="3" />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Save</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    @if ($this->history)
        <x-ui.modal :title="'History of '.$this->history->accession_no" max="max-w-lg" wire:key="history-modal">
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach ($this->history->events as $e)
                    <li class="py-2" wire:key="ev-{{ $e->id }}">
                        <div class="flex justify-between gap-3"><span class="font-medium text-gray-900">{{ ucfirst(str_replace('_', ' ', $e->type)) }}@if ($e->to_status) <span class="font-normal text-gray-500">→ {{ \App\Models\BookCopy::STATUSES[$e->to_status] ?? $e->to_status }}</span>@endif</span><span class="text-xs text-gray-400">{{ $e->created_at->format('d M Y H:i') }}</span></div>
                        <p class="text-xs text-gray-500">{{ $e->user?->name ?? 'System' }}{{ $e->note ? ' · '.$e->note : '' }}</p>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4 flex justify-end"><x-ui.button variant="secondary" wire:click="closeModal">Close</x-ui.button></div>
        </x-ui.modal>
    @endif

    @if ($confirmDelete)
        <x-ui.modal title="Delete this title?" max="max-w-md" wire:key="del-modal">
            <p class="text-sm text-gray-700">This removes the record{{ $b->isEbook() ? ' and its file' : ' and its copies' }} for good. It is only possible because nothing has been issued or opened yet.</p>
            <div class="mt-4 flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button variant="danger" wire:click="delete">Delete</x-ui.button></div>
        </x-ui.modal>
    @endif
</div>
