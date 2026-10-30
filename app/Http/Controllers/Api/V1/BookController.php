<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\EbookFileController;
use App\Http\Resources\Api\V1\Present;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The catalogue on the floor: search books and e-books, look up a scanned barcode, open an e-book.
 * Adding and editing books (import, covers, labels) stays on the web panel.
 */
class BookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requireModule('books|ebooks');
        $this->authorizePermission('books.view|ebooks.view');
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', 'in:physical,ebook'], 'category_id' => ['nullable', 'integer'], 'archived' => ['nullable', 'boolean']]);

        $page = Book::query()->with('category:id,name')
            ->withCount(['copies', 'copies as available_count' => fn ($q) => $q->where('status', BookCopy::AVAILABLE)])
            ->search($f['q'] ?? null)
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when(! $this->can('books.view'), fn ($q) => $q->where('type', Book::EBOOK))
            ->when(! $this->can('ebooks.view'), fn ($q) => $q->where('type', Book::PHYSICAL))
            ->when($f['category_id'] ?? null, fn ($q, $c) => $q->where(fn ($w) => $w->where('category_id', $c)->orWhereIn('category_id', BookCategory::where('parent_id', $c)->select('id'))))
            ->when(! ($f['archived'] ?? false), fn ($q) => $q->where('status', '!=', 'archived'))
            ->orderBy('title')->paginate($this->perPage());

        return $this->paged($page, fn ($b) => $this->book($b), ['categories' => BookCategory::orderBy('name')->get(['id', 'name', 'parent_id'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'parent_id' => $c->parent_id])->all()]);
    }

    public function show(int $book): JsonResponse
    {
        $this->requireModule('books|ebooks');
        $this->authorizePermission('books.view|ebooks.view');
        $b = Book::with(['category:id,name', 'copies' => fn ($q) => $q->orderBy('accession_no')])->withCount(['copies', 'copies as available_count' => fn ($q) => $q->where('status', BookCopy::AVAILABLE)])->findOrFail($book);

        return $this->ok($this->book($b) + [
            'description' => $b->description, 'publisher' => $b->publisher, 'edition' => $b->edition, 'language' => $b->language, 'publication_year' => $b->publication_year,
            'tags' => $b->tags, 'location' => $b->locationText(),
            'copy_list' => $b->copies->map(fn ($c) => $this->copy($c))->all(),
            'ebook' => $b->isEbook() ? ['has_file' => $b->hasFile(), 'external_url' => $b->external_url, 'allow_download' => (bool) $b->allow_download, 'licence_expires_on' => Present::d($b->licence_expires_on),
                'open_url' => route('api.v1.library.ebooks.open', ['library' => Tenancy::slug(), 'book' => $b->id])] : null,
        ]);
    }

    /** A scanned barcode or accession number: which copy, which book, and who has it. */
    public function lookup(Request $request): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('books.view|circulation.view');
        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:60']])['code']);
        $copy = BookCopy::with('book:id,title,author,isbn')->where(fn ($q) => $q->where('barcode', $code)->orWhere('accession_no', $code))->first();
        abort_unless($copy, 404, "No copy with code {$code}.");
        $open = \App\Models\LoanItem::with('student:id,name,student_code')->where('book_copy_id', $copy->id)->open()->first();

        return $this->ok($this->copy($copy) + [
            'book' => ['id' => $copy->book?->id, 'title' => $copy->book?->title, 'author' => $copy->book?->author, 'isbn' => $copy->book?->isbn],
            'on_loan' => $open ? ['loan_item_id' => $open->id, 'student' => ['id' => $open->student?->id, 'name' => $open->student?->name, 'code' => $open->student?->student_code], 'due_on' => Present::d($open->due_on), 'overdue' => $open->isOverdue()] : null,
        ]);
    }

    /** The cover image (private file). */
    public function cover(Request $request, int $book)
    {
        $this->requireModule('books|ebooks');

        return app(\App\Http\Controllers\BookCoverController::class)->show($request, Tenancy::slug(), (string) $book);
    }

    /** Open an e-book: the file (PDF inline, others as a download when allowed) or a redirect to its link. Every opening is logged. */
    public function open(Request $request, int $book)
    {
        $this->requireModule('ebooks');

        return app(EbookFileController::class)->show($request, Tenancy::slug(), (string) $book, $request->query('action') === 'download' ? 'download' : 'view');
    }

    protected function book(Book $b): array
    {
        return [
            'id' => $b->id, 'type' => $b->type, 'title' => $b->title, 'subtitle' => $b->subtitle, 'author' => $b->author, 'isbn' => $b->isbn,
            'category' => $b->category ? ['id' => $b->category->id, 'name' => $b->category->name] : null,
            'cover_url' => $b->cover_path ? route('api.v1.library.books.cover', ['library' => Tenancy::slug(), 'book' => $b->id]) : null,
            'copies' => (int) ($b->copies_count ?? 0), 'available' => (int) ($b->available_count ?? 0), 'status' => $b->status,
            'shelf' => $b->shelf, 'rack' => $b->rack,
        ];
    }

    protected function copy(BookCopy $c): array
    {
        return ['id' => $c->id, 'accession_no' => $c->accession_no, 'barcode' => $c->barcode, 'status' => $c->status, 'status_label' => BookCopy::STATUSES[$c->status] ?? $c->status, 'location' => $c->location];
    }
}
