<?php

use App\Actions\Books\AddCopies;
use App\Actions\Books\SaveBook;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Book;
use App\Models\BookCategory;
use App\Support\Entitlements;
use App\Support\Isbn;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithFileUploads;

    public ?int $bookId = null;
    public string $type = Book::PHYSICAL;

    public string $title = '';
    public string $subtitle = '';
    public string $isbn = '';
    public string $author = '';
    public string $publisher = '';
    public string $edition = '';
    public string $language = '';
    public string $publication_year = '';
    public string $category_id = '';
    public string $tags = '';
    public string $description = '';

    // physical
    public string $shelf = '';
    public string $rack = '';
    public string $section = '';
    public string $location = '';
    public string $purchase_price = '';
    public string $supplier = '';
    public string $purchased_on = '';
    public string $initial_copies = '1';

    // e-book
    public string $external_url = '';
    public string $licence_expires_on = '';
    public bool $allow_download = true;

    public $cover = null;
    public $ebookFile = null;
    public bool $removeCover = false;
    public ?string $existingCover = null;
    public ?string $existingFile = null;
    public ?string $lookupNote = null;

    public function mount(?string $book = null): void
    {
        if ($book === null) {
            $this->type = request()->query('type') === 'ebook' ? Book::EBOOK : Book::PHYSICAL;
            $this->authorizePermission($this->type === Book::EBOOK ? 'ebooks.manage' : 'books.manage');
            abort_unless(Entitlements::hasModule($this->type === Book::EBOOK ? 'ebooks' : 'books'), 403);

            return;
        }

        $model = Book::findOrFail($book);   // tenant-scoped
        $this->authorizePermission($model->isEbook() ? 'ebooks.manage' : 'books.manage');
        abort_unless(Entitlements::hasModule($model->isEbook() ? 'ebooks' : 'books'), 403);

        $this->bookId = $model->id;
        $this->type = $model->type;
        $this->fill([
            'title' => $model->title, 'subtitle' => (string) $model->subtitle, 'isbn' => (string) $model->isbn, 'author' => (string) $model->author,
            'publisher' => (string) $model->publisher, 'edition' => (string) $model->edition, 'language' => (string) $model->language,
            'publication_year' => (string) $model->publication_year, 'category_id' => (string) $model->category_id,
            'tags' => implode(', ', (array) $model->tags), 'description' => (string) $model->description,
            'shelf' => (string) $model->shelf, 'rack' => (string) $model->rack, 'section' => (string) $model->section, 'location' => (string) $model->location,
            'purchase_price' => (string) $model->purchase_price, 'supplier' => (string) $model->supplier, 'purchased_on' => $model->purchased_on?->toDateString() ?? '',
            'external_url' => (string) $model->external_url, 'licence_expires_on' => $model->licence_expires_on?->toDateString() ?? '',
            'allow_download' => (bool) $model->allow_download,
        ]);
        $this->existingCover = $model->cover_path;
        $this->existingFile = $model->ebook_name;
    }

    /** Typing or scanning a valid ISBN on a new, still-empty book fills it in automatically. */
    public function updatedIsbn(): void
    {
        $this->lookupNote = null;
        if (! $this->bookId && trim($this->title) === '' && \App\Support\Isbn::valid($this->isbn)) {
            $this->lookupIsbn();
        }
    }

    /** Fills only what is still empty, so nothing you typed is overwritten. */
    public function lookupIsbn(): void
    {
        $this->authorizePermission($this->type === Book::EBOOK ? 'ebooks.manage' : 'books.manage');
        $this->lookupNote = null;

        if (! \App\Support\LibrarySettings::get('isbn_lookup')) {
            $this->lookupNote = 'Looking up ISBNs online is switched off in Settings.';

            return;
        }
        if (! \App\Support\Isbn::valid($this->isbn)) {
            $this->lookupNote = 'Enter a valid ISBN first.';

            return;
        }

        $found = \App\Support\IsbnLookup::find($this->isbn);
        if (! $found) {
            $this->lookupNote = 'Nothing found for this ISBN. You can fill the details in by hand.';

            return;
        }

        foreach (['title', 'subtitle', 'author', 'publisher', 'language', 'description'] as $field) {
            if (trim((string) $this->{$field}) === '' && filled($found[$field])) {
                $this->{$field} = $found[$field];
            }
        }
        if (trim($this->publication_year) === '' && $found['publication_year']) {
            $this->publication_year = (string) $found['publication_year'];
        }

        $this->lookupNote = "Details filled in from {$found['source']}. Please check them.";
    }

    #[Computed]
    public function categories()
    {
        return BookCategory::with('parent')->orderBy('name')->get()->sortBy(fn ($c) => $c->fullName())->values();
    }

    /** Live warning while typing or scanning an ISBN that is already in the catalogue. */
    #[Computed]
    public function isbnNotice(): ?string
    {
        $isbn = Isbn::normalize($this->isbn);
        if ($isbn === null) {
            return null;
        }
        if (! Isbn::valid($isbn)) {
            return 'This is not a valid ISBN (check the digits).';
        }

        $dup = Book::where('type', $this->type)->where('isbn', $isbn)->when($this->bookId, fn ($q) => $q->whereKeyNot($this->bookId))->first();

        return $dup ? "Already in the catalogue as “{$dup->title}”. Add a copy to that title instead." : null;
    }

    public function save()
    {
        $isEbook = $this->type === Book::EBOOK;
        $this->authorizePermission($isEbook ? 'ebooks.manage' : 'books.manage');

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:30'],
            'author' => ['nullable', 'string', 'max:255'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'edition' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:40'],
            'publication_year' => ['nullable', 'integer', 'min:1000', 'max:'.(today()->year + 1)],
            'category_id' => ['nullable', 'integer'],
            'tags' => ['nullable', 'string', 'max:400'],
            'description' => ['nullable', 'string', 'max:5000'],
            'shelf' => ['nullable', 'string', 'max:50'], 'rack' => ['nullable', 'string', 'max:50'], 'section' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:100'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'purchased_on' => ['nullable', 'date', 'before_or_equal:today'],
            'initial_copies' => [$this->bookId || $isEbook ? 'nullable' : 'required', 'integer', 'min:0', 'max:'.AddCopies::MAX_AT_ONCE],
            'external_url' => ['nullable', 'url', 'max:500'],
            'licence_expires_on' => ['nullable', 'date'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'ebookFile' => ['nullable', 'file', 'mimes:pdf,epub', 'max:51200'],
        ], [], ['category_id' => 'category', 'initial_copies' => 'number of copies', 'external_url' => 'link', 'ebookFile' => 'e-book file']);

        try {
            $book = app(SaveBook::class)(
                $this->all() + ['type' => $this->type],
                $this->bookId ? Book::findOrFail($this->bookId) : null,
                $this->cover, $this->ebookFile, $this->removeCover,
            );

            if (! $this->bookId && ! $isEbook && (int) $this->initial_copies > 0) {
                app(AddCopies::class)($book, (int) $this->initial_copies);
            }
        } catch (BusinessRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('notice', $this->bookId ? 'Saved.' : ($isEbook ? 'E-book added.' : 'Book added'.((int) $this->initial_copies > 0 ? ' with '.(int) $this->initial_copies.' cop'.((int) $this->initial_copies === 1 ? 'y' : 'ies').'.' : '.')));

        return $this->redirect(route('app.books.show', ['library' => Tenancy::slug(), 'book' => $book->id]), navigate: true);
    }
}
?>

<div class="max-w-4xl space-y-5">
    <x-slot:header><x-ui.page-title :title="($bookId ? 'Edit ' : 'Add ').($type === 'ebook' ? 'e-book' : 'book')" subtitle="Catalogue entry" /></x-slot:header>

    @php $isEbook = $type === 'ebook'; $slug = \App\Support\Tenancy::slug(); @endphp

    <form wire:submit="save" class="space-y-5">
        @error('form') <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div> @enderror

        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-4 text-sm font-semibold text-gray-900">Details</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="title" label="Title" required wire:model="title" class="sm:col-span-2" />
                <x-ui.input name="subtitle" label="Subtitle" wire:model="subtitle" class="sm:col-span-2" />
                <div>
                    <x-ui.input name="isbn" label="ISBN" wire:model.live.debounce.400ms="isbn" hint="10 or 13 digits. You can scan it." />
                    @if (\App\Support\LibrarySettings::get('isbn_lookup'))
                        <button type="button" wire:click="lookupIsbn" wire:loading.attr="disabled" wire:target="lookupIsbn" class="mt-1 text-xs font-medium text-brand-600 hover:text-brand-800"><span wire:loading.remove wire:target="lookupIsbn">Fill details from ISBN</span><span wire:loading wire:target="lookupIsbn">Looking up…</span></button>
                    @endif
                    @if ($lookupNote) <p class="mt-1 text-xs text-gray-600">{{ $lookupNote }}</p> @endif
                    @if ($this->isbnNotice) <p class="mt-1 text-xs text-amber-700">{{ $this->isbnNotice }}</p> @endif
                </div>
                <x-ui.input name="author" label="Author" wire:model="author" />
                <x-ui.input name="publisher" label="Publisher" wire:model="publisher" />
                <x-ui.input name="edition" label="Edition" wire:model="edition" />
                <x-ui.input name="language" label="Language" wire:model="language" />
                <x-ui.input name="publication_year" label="Publication year" type="number" wire:model="publication_year" />
                <x-ui.select name="category_id" label="Category" wire:model="category_id">
                    <option value="">None</option>
                    @foreach ($this->categories as $c) <option value="{{ $c->id }}">{{ $c->fullName() }}</option> @endforeach
                </x-ui.select>
                <x-ui.input name="tags" label="Tags" wire:model="tags" hint="Separate with commas." />
                <x-ui.textarea name="description" label="Description" wire:model="description" class="sm:col-span-2" />
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">Cover image</label>
                    @if ($existingCover && ! $cover && ! $removeCover)
                        <div class="mt-2 flex items-center gap-3"><img src="{{ route('app.books.cover', ['library' => $slug, 'book' => $bookId]) }}" alt="" class="h-20 w-14 rounded object-cover shadow-sm">
                            <label class="flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" wire:model="removeCover" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> Remove cover</label></div>
                    @endif
                    @if ($cover && $cover->isPreviewable()) <img src="{{ $cover->temporaryUrl() }}" alt="" class="mt-2 h-20 w-14 rounded object-cover shadow-sm"> @endif
                    <input type="file" wire:model="cover" accept=".jpg,.jpeg,.png,.webp" class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700">
                    @error('cover') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        @if (! $isEbook)
            <section class="rounded-xl border border-gray-200 bg-white p-5">
                <h3 class="mb-1 text-sm font-semibold text-gray-900">Where it is kept</h3>
                <p class="mb-4 text-xs text-gray-500">Shown to staff when finding the book.</p>
                <div class="grid gap-4 sm:grid-cols-4">
                    <x-ui.input name="shelf" label="Shelf" wire:model="shelf" /><x-ui.input name="rack" label="Rack" wire:model="rack" />
                    <x-ui.input name="section" label="Section" wire:model="section" /><x-ui.input name="location" label="Room / location" wire:model="location" />
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-5">
                <h3 class="mb-1 text-sm font-semibold text-gray-900">Purchase</h3>
                <p class="mb-4 text-xs text-gray-500">Used as the default for each new copy.</p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.input name="purchase_price" label="Purchase price (₹)" type="number" step="0.01" min="0" wire:model="purchase_price" />
                    <x-ui.input name="supplier" label="Supplier" wire:model="supplier" />
                    <x-ui.input name="purchased_on" label="Purchase date" type="date" wire:model="purchased_on" />
                    @unless ($bookId)
                        <x-ui.input name="initial_copies" label="Number of copies to add now" type="number" min="0" max="500" wire:model="initial_copies" hint="Each copy gets its own accession number and barcode." />
                    @endunless
                </div>
            </section>
        @else
            <section class="rounded-xl border border-gray-200 bg-white p-5">
                <h3 class="mb-1 text-sm font-semibold text-gray-900">E-book file</h3>
                <p class="mb-4 text-xs text-gray-500">Upload a PDF or EPUB (up to 50 MB), or give a link. Files are private: only your members can open them.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700">File</label>
                        @if ($existingFile && ! $ebookFile) <p class="mt-1 text-xs text-gray-500">Current file: <strong>{{ $existingFile }}</strong>. Choosing a new file replaces it.</p> @endif
                        <input type="file" wire:model="ebookFile" accept=".pdf,.epub" class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700">
                        <div wire:loading wire:target="ebookFile" class="mt-1 text-xs text-gray-500">Uploading…</div>
                        @error('ebookFile') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <x-ui.input name="external_url" label="…or a link" type="url" wire:model="external_url" class="sm:col-span-2" hint="Used when there is no file." />
                    <x-ui.input name="licence_expires_on" label="Licence ends on" type="date" wire:model="licence_expires_on" hint="After this date it can no longer be opened. Leave empty for no limit." />
                    <label class="flex items-start gap-3 self-end pb-2 text-sm text-gray-700"><input type="checkbox" wire:model="allow_download" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Allow download</span><br><span class="text-xs text-gray-500">Off: PDFs can only be read online.</span></span></label>
                </div>
            </section>
        @endif

        <div class="flex items-center gap-3">
            <x-ui.button type="submit" wire:target="save,cover,ebookFile"><span wire:loading.remove wire:target="save">Save</span><span wire:loading wire:target="save">Saving…</span></x-ui.button>
            <a href="{{ $bookId ? route('app.books.show', ['library' => $slug, 'book' => $bookId]) : route('app.books.index', ['library' => $slug]) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-800">Cancel</a>
        </div>
    </form>
</div>
