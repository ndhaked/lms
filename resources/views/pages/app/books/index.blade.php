<?php

use App\Actions\Books\ManageBookLifecycle;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
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
    public string $type = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $status = 'active';

    #[Url]
    public string $stock = '';          // available | none (no copy on the shelf)

    public ?int $confirmDelete = null;

    public function mount(): void
    {
        abort_unless(count($this->allowedTypes) > 0, 403);
    }

    /** Titles this person may see: physical books and/or e-books, depending on the plan's modules and their permissions. */
    #[Computed]
    public function allowedTypes(): array
    {
        $types = [];
        if (Entitlements::hasModule('books') && $this->can('books.view')) {
            $types[] = Book::PHYSICAL;
        }
        if (Entitlements::hasModule('ebooks') && $this->can('ebooks.view')) {
            $types[] = Book::EBOOK;
        }

        return $types;
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'type', 'category', 'status', 'stock'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function categories()
    {
        return BookCategory::with('parent')->orderBy('name')->get()->sortBy(fn ($c) => $c->fullName())->values();
    }

    protected function base()
    {
        return Book::query()->whereIn('type', $this->allowedTypes);
    }

    #[Computed]
    public function totals(): array
    {
        $types = $this->allowedTypes;
        $titles = $this->base()->where('status', 'active');

        return [
            'titles' => (clone $titles)->where('type', Book::PHYSICAL)->count(),
            'ebooks' => (clone $titles)->where('type', Book::EBOOK)->count(),
            'copies' => in_array(Book::PHYSICAL, $types, true) ? BookCopy::inStock()->count() : 0,
            'available' => in_array(Book::PHYSICAL, $types, true) ? BookCopy::where('status', 'available')->count() : 0,
        ];
    }

    #[Computed]
    public function rows()
    {
        return $this->base()
            ->with('category.parent')
            ->withCount(['copies as copies_total' => fn ($q) => $q->inStock(), 'copies as copies_available' => fn ($q) => $q->where('status', 'available')])
            ->search($this->search)
            ->when(in_array($this->type, [Book::PHYSICAL, Book::EBOOK], true), fn ($q) => $q->where('type', $this->type))
            ->when($this->category !== '', fn ($q) => $q->where('category_id', (int) $this->category))
            ->when(in_array($this->status, ['active', 'archived'], true), fn ($q) => $q->where('status', $this->status))
            ->when($this->stock === 'available', fn ($q) => $q->where('type', Book::PHYSICAL)->whereHas('copies', fn ($c) => $c->where('status', 'available')))
            ->when($this->stock === 'none', fn ($q) => $q->where('type', Book::PHYSICAL)->whereDoesntHave('copies', fn ($c) => $c->where('status', 'available')))
            ->latest('id')->paginate(15);
    }

    protected function find(int $id): Book
    {
        $book = Book::findOrFail($id);   // tenant-scoped
        abort_unless(in_array($book->type, $this->allowedTypes, true), 403);

        return $book;
    }

    public function archive(int $id): void
    {
        $book = $this->find($id);
        $this->authorizePermission($book->isEbook() ? 'ebooks.manage' : 'books.manage');

        try {
            app(ManageBookLifecycle::class)->archive($book);
            $this->dispatch('notify', message: 'Archived. It no longer appears for issuing.');
        } catch (BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
        unset($this->rows, $this->totals);
    }

    public function restore(int $id): void
    {
        $book = $this->find($id);
        $this->authorizePermission($book->isEbook() ? 'ebooks.manage' : 'books.manage');

        try {
            app(ManageBookLifecycle::class)->restore($book);
            $this->dispatch('notify', message: 'Restored.');
        } catch (BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
        unset($this->rows, $this->totals);
    }

    public function askDelete(int $id): void
    {
        $this->find($id);
        $this->confirmDelete = $id;
    }

    public function delete(): void
    {
        $book = $this->find((int) $this->confirmDelete);
        $this->authorizePermission($book->isEbook() ? 'ebooks.manage' : 'books.manage');

        try {
            app(ManageBookLifecycle::class)->delete($book);
            $this->dispatch('notify', message: 'Deleted.');
        } catch (BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }

        $this->confirmDelete = null;
        unset($this->rows, $this->totals);
    }

    public function closeModal(): void
    {
        $this->confirmDelete = null;
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Books" subtitle="Catalogue of books and e-books" /></x-slot:header>

    @php
        $slug = \App\Support\Tenancy::slug();
        $types = $this->allowedTypes;
        $hasBooks = in_array(\App\Models\Book::PHYSICAL, $types, true);
        $hasEbooks = in_array(\App\Models\Book::EBOOK, $types, true);
        $canExport = $hasBooks && $this->can('books.export') && \App\Support\LibrarySettings::get('export_enabled') && \App\Support\Entitlements::hasModule('exports');
        $t = $this->totals;
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            @if ($hasBooks && \App\Support\Entitlements::hasModule('books') && $this->can('books.manage'))
                <a href="{{ route('app.books.create', ['library' => $slug]) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700"><x-lm.icon name="plus" class="h-4 w-4" /> Add book</a>
            @endif
            @if ($hasEbooks && $this->can('ebooks.manage'))
                <a href="{{ route('app.books.create', ['library' => $slug, 'type' => 'ebook']) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"><x-lm.icon name="tablet" class="h-4 w-4" /> Add e-book</a>
            @endif
            @if ($this->can('books.manage'))
                <a href="{{ route('app.books.categories', ['library' => $slug]) }}" wire:navigate class="rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Categories</a>
            @endif
            @if ($hasBooks && $this->can('books.import'))
                <a href="{{ route('app.books.import', ['library' => $slug]) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"><x-lm.icon name="upload" class="h-4 w-4" /> Import</a>
            @endif
            @if ($hasBooks && $this->can('books.audit'))
                <a href="{{ route('app.books.audits', ['library' => $slug]) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"><x-lm.icon name="check-circle" class="h-4 w-4" /> Stock-take</a>
            @endif
            @if ($hasBooks && $this->can('books.copies'))
                <a href="{{ route('app.books.labels', ['library' => $slug]) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"><x-lm.icon name="printer" class="h-4 w-4" /> Barcode labels</a>
            @endif
            @if ($canExport)
                <a href="{{ route('app.books.export', ['library' => $slug, 'format' => 'csv']) }}" class="rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">CSV</a>
                <a href="{{ route('app.books.export', ['library' => $slug, 'format' => 'xlsx']) }}" class="rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Excel</a>
                <a href="{{ route('app.books.export', ['library' => $slug, 'format' => 'pdf']) }}" class="rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">PDF</a>
            @endif
        </div>
        <dl class="flex gap-5 text-right text-xs text-gray-500">
            @if ($hasBooks)
                <div><dt>Titles</dt><dd class="text-base font-semibold text-gray-900">{{ number_format($t['titles']) }}</dd></div>
                <div><dt>Copies</dt><dd class="text-base font-semibold text-gray-900">{{ number_format($t['copies']) }}</dd></div>
                <div><dt>Available</dt><dd class="text-base font-semibold text-brand-600">{{ number_format($t['available']) }}</dd></div>
            @endif
            @if ($hasEbooks) <div><dt>E-books</dt><dd class="text-base font-semibold text-gray-900">{{ number_format($t['ebooks']) }}</dd></div> @endif
        </dl>
    </div>

    <div class="flex flex-wrap gap-3">
        <div class="relative min-w-64 flex-1">
            <x-lm.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search title, author, publisher, ISBN or accession no…"
                class="w-full rounded-lg border-gray-300 py-2 pl-9 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
        @if ($hasBooks && $hasEbooks)
            <select wire:model.live="type" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">All types</option><option value="physical">Books</option><option value="ebook">E-books</option>
            </select>
        @endif
        <select wire:model.live="category" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="">All categories</option>
            @foreach ($this->categories as $c) <option value="{{ $c->id }}">{{ $c->fullName() }}</option> @endforeach
        </select>
        @if ($hasBooks)
            <select wire:model.live="stock" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">Any stock</option><option value="available">Copy available</option><option value="none">No copy available</option>
            </select>
        @endif
        <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="active">Active</option><option value="archived">Archived</option><option value="all">All</option>
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left text-xs text-gray-500">
                <th class="px-4 py-3 font-medium">ID</th><th class="px-4 py-3 font-medium">Book</th><th class="px-4 py-3 font-medium">Category</th>
                <th class="px-4 py-3 font-medium">Author</th><th class="px-4 py-3 font-medium">Type</th><th class="px-4 py-3 font-medium">Copies</th><th class="px-4 py-3"></th>
            </tr></thead>
            <tbody>
                @forelse ($this->rows as $b)
                    <tr class="border-t border-gray-100 {{ $b->isArchived() ? 'bg-gray-50/70 text-gray-500' : '' }}" wire:key="b-{{ $b->id }}">
                        <td class="px-4 py-3 text-gray-500">#{{ $b->id }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                @if ($b->cover_path)
                                    <img src="{{ route('app.books.cover', ['library' => $slug, 'book' => $b->id]) }}" alt="" loading="lazy" class="h-12 w-9 shrink-0 rounded object-cover shadow-sm">
                                @else
                                    <span class="flex h-12 w-9 shrink-0 items-center justify-center rounded bg-brand-50 text-brand-600"><x-lm.icon name="{{ $b->isEbook() ? 'tablet' : 'book' }}" class="h-5 w-5" /></span>
                                @endif
                                <div class="min-w-0">
                                    <a href="{{ route('app.books.show', ['library' => $slug, 'book' => $b->id]) }}" wire:navigate class="block max-w-xs truncate font-medium text-gray-900 hover:text-brand-700">{{ $b->title }}</a>
                                    <p class="text-xs text-gray-400">{{ $b->isbn ? 'ISBN '.$b->isbn : ($b->isEbook() ? ($b->hasFile() ? strtoupper(pathinfo($b->ebook_name ?? '', PATHINFO_EXTENSION)) : 'Link') : 'No ISBN') }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $b->category?->fullName() ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $b->author ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <x-ui.badge :color="$b->isEbook() ? 'purple' : 'blue'">{{ \App\Models\Book::TYPES[$b->type] }}</x-ui.badge>
                            @if ($b->isArchived()) <x-ui.badge color="gray" class="ml-1">Archived</x-ui.badge> @endif
                            @if ($b->isEbook() && $b->licenceExpired()) <x-ui.badge color="red" class="ml-1">Licence ended</x-ui.badge> @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($b->isEbook()) <span class="text-gray-400">Digital</span>
                            @else <span class="{{ $b->copies_available > 0 ? 'font-semibold text-brand-700' : 'font-semibold text-red-600' }}">{{ $b->copies_available }}</span><span class="text-gray-400"> / {{ $b->copies_total }}</span> @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <a href="{{ route('app.books.show', ['library' => $slug, 'book' => $b->id]) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-800">View</a>
                            @if ($this->can($b->isEbook() ? 'ebooks.manage' : 'books.manage'))
                                <a href="{{ route('app.books.edit', ['library' => $slug, 'book' => $b->id]) }}" wire:navigate class="ml-3 font-medium text-gray-600 hover:text-gray-900">Edit</a>
                                @if ($b->isArchived())
                                    <button wire:click="restore({{ $b->id }})" class="ml-3 font-medium text-gray-600 hover:text-gray-900">Restore</button>
                                @else
                                    <button wire:click="archive({{ $b->id }})" wire:confirm="Archive “{{ $b->title }}”? It stops appearing for issuing; its history is kept." class="ml-3 font-medium text-amber-600 hover:text-amber-800">Archive</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-400">
                        @if ($search !== '' || $category !== '' || $type !== '' || $stock !== '') No books match these filters.
                        @else No books yet. @if ($hasBooks && $this->can('books.manage')) Add your first book, or import a sheet. @endif @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->rows->links() }}

    @if ($confirmDelete)
        <x-ui.modal title="Delete this title?" max="max-w-md" wire:key="del-modal">
            <p class="text-sm text-gray-700">Only titles that were never used can be deleted. This removes the record and its files for good.</p>
            <div class="mt-4 flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button variant="danger" wire:click="delete">Delete</x-ui.button></div>
        </x-ui.modal>
    @endif
</div>
