<?php

namespace App\Actions\Books;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookCategory;
use App\Support\Entitlements;
use App\Support\Isbn;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Creates or edits a catalogue title (physical book or e-book). Domain rules live here so that every entry
 * point (the form, the importer) obeys the same ones: permission, plan module, valid ISBN, no duplicates.
 */
class SaveBook
{
    public const EBOOK_EXTENSIONS = ['pdf', 'epub'];
    public const COVER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public static function assertAllowed(string $type): void
    {
        abort_unless(auth()->user()?->can($type === Book::EBOOK ? 'ebooks.manage' : 'books.manage'), 403);

        if (! Entitlements::hasModule($type === Book::EBOOK ? 'ebooks' : 'books')) {
            throw new BusinessRuleException(($type === Book::EBOOK ? 'E-books are' : 'Books are').' not enabled for this library.');
        }
    }

    /** @param array<string, mixed> $d */
    public function __invoke(array $d, ?Book $book = null, ?UploadedFile $cover = null, ?UploadedFile $ebookFile = null, bool $removeCover = false): Book
    {
        $type = $book?->type ?? ($d['type'] ?? Book::PHYSICAL);
        if (! array_key_exists($type, Book::TYPES)) {
            throw new BusinessRuleException('Choose book or e-book.');
        }
        if ($book && isset($d['type']) && $d['type'] !== $book->type) {
            throw new BusinessRuleException('The type of a title cannot be changed. Add a new title instead.');
        }
        self::assertAllowed($type);

        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new BusinessRuleException('Enter the title (up to 255 characters).');
        }

        $isbn = Isbn::normalize($d['isbn'] ?? null);
        if ($isbn !== null) {
            if (! Isbn::valid($isbn)) {
                throw new BusinessRuleException("“{$d['isbn']}” is not a valid ISBN. Check the digits.");
            }
            $dup = Book::where('type', $type)->where('isbn', $isbn)->when($book, fn ($q) => $q->whereKeyNot($book->id))->first();
            if ($dup) {
                throw new BusinessRuleException("This ISBN is already in the catalogue as “{$dup->title}”".($dup->isArchived() ? ' (archived: restore it instead).' : '. Add a copy to that title instead.'));
            }
        }

        $categoryId = filled($d['category_id'] ?? null) ? (int) $d['category_id'] : null;
        if ($categoryId !== null && ! BookCategory::whereKey($categoryId)->exists()) {
            throw new BusinessRuleException('Choose a category from the list.');
        }

        $year = filled($d['publication_year'] ?? null) ? (int) $d['publication_year'] : null;
        if ($year !== null && ($year < 1000 || $year > (int) today()->year + 1)) {
            throw new BusinessRuleException('Enter a valid publication year.');
        }

        $tags = $d['tags'] ?? [];
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }
        $tags = collect($tags)->map(fn ($t) => mb_substr(trim((string) $t), 0, 30))->filter()->unique(fn ($t) => mb_strtolower($t))->take(10)->values()->all();

        $price = filled($d['purchase_price'] ?? null) ? $d['purchase_price'] : null;
        if ($price !== null && (! is_numeric($price) || (float) $price < 0 || (float) $price > 9999999)) {
            throw new BusinessRuleException('Enter a valid purchase price.');
        }

        $attrs = [
            'title' => $title,
            'subtitle' => $this->text($d['subtitle'] ?? null, 255),
            'isbn' => $isbn,
            'author' => $this->text($d['author'] ?? null, 255),
            'publisher' => $this->text($d['publisher'] ?? null, 255),
            'edition' => $this->text($d['edition'] ?? null, 50),
            'language' => $this->text($d['language'] ?? null, 40),
            'publication_year' => $year,
            'category_id' => $categoryId,
            'tags' => $tags ?: null,
            'description' => $this->text($d['description'] ?? null, 5000),
        ];

        if ($type === Book::PHYSICAL) {
            $attrs += [
                'shelf' => $this->text($d['shelf'] ?? null, 50), 'rack' => $this->text($d['rack'] ?? null, 50), 'section' => $this->text($d['section'] ?? null, 50),
                'location' => $this->text($d['location'] ?? null, 100),
                'purchase_price' => $price !== null ? number_format((float) $price, 2, '.', '') : null,
                'supplier' => $this->text($d['supplier'] ?? null, 150),
                'purchased_on' => filled($d['purchased_on'] ?? null) ? $d['purchased_on'] : null,
            ];
        } else {
            $url = $this->text($d['external_url'] ?? null, 500);
            if ($url !== null && (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))) {
                throw new BusinessRuleException('The e-book link must be a web address starting with http:// or https://.');
            }
            if (! $ebookFile && ! $book?->hasFile() && $url === null) {
                throw new BusinessRuleException('Upload the e-book file or give a link to it.');
            }
            $attrs += [
                'external_url' => $url,
                'licence_expires_on' => filled($d['licence_expires_on'] ?? null) ? $d['licence_expires_on'] : null,
                'allow_download' => (bool) ($d['allow_download'] ?? true),
            ];
        }

        if ($cover) {
            $this->assertFile($cover, self::COVER_EXTENSIONS, 2048, 'The cover must be a JPG, PNG or WebP image up to 2 MB.');
        }
        if ($ebookFile) {
            $this->assertFile($ebookFile, self::EBOOK_EXTENSIONS, 51200, 'The e-book must be a PDF or EPUB file up to 50 MB.');
        }

        $disk = Storage::disk('local');
        $libraryId = Tenancy::currentId();
        $oldFiles = [];

        $saved = DB::transaction(function () use ($book, $type, $attrs, $cover, $ebookFile, $removeCover, $disk, $libraryId, &$oldFiles) {
            $isNew = ! $book;
            $book ??= new Book(['type' => $type]);
            $book->fill($attrs);
            $before = $isNew ? [] : $book->getOriginal();

            if ($cover) {
                $oldFiles[] = $book->cover_path;
                $book->cover_path = $cover->store("book-covers/{$libraryId}", 'local');
            } elseif ($removeCover) {
                $oldFiles[] = $book->cover_path;
                $book->cover_path = null;
            }

            if ($ebookFile) {
                $oldFiles[] = $book->ebook_path;
                $book->ebook_path = $ebookFile->store("ebooks/{$libraryId}", 'local');
                $book->ebook_name = mb_substr($ebookFile->getClientOriginalName(), 0, 200);
                $book->ebook_mime = strtolower($ebookFile->getClientOriginalExtension()) === 'epub' ? 'application/epub+zip' : 'application/pdf';
                $book->ebook_size = $ebookFile->getSize();
            }

            if ($isNew) {
                $book->created_by = auth()->id();
            }
            $changed = array_keys($book->getDirty());
            $book->save();

            AuditLog::record($isNew ? 'book.created' : 'book.updated', $book, ['title' => $book->title, 'type' => $type] + ($isNew ? [] : ['changed' => $changed]));

            return $book;
        });

        foreach (array_filter($oldFiles) as $path) {
            $disk->delete($path);
        }

        return $saved;
    }

    protected function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    protected function assertFile(UploadedFile $file, array $extensions, int $maxKb, string $message): void
    {
        if (! in_array(strtolower($file->getClientOriginalExtension()), $extensions, true) || $file->getSize() > $maxKb * 1024) {
            throw new BusinessRuleException($message);
        }
    }
}
