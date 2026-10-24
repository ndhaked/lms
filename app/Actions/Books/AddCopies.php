<?php

namespace App\Actions\Books;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookCopyEvent;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use App\Support\NextNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AddCopies
{
    public const MAX_AT_ONCE = 500;

    /**
     * Adds physical copies of a book. Accession numbers are generated (ACC-000001 ...) unless one number is given
     * for a single copy, e.g. when moving an existing register into the system. The barcode starts as the
     * accession number so a scanner finds the copy.
     *
     * @param  array<string, mixed>  $details  purchase_price, supplier, purchased_on, location, note
     * @return Collection<int, BookCopy>
     */
    public function __invoke(Book $book, int $count = 1, array $details = [], ?string $accessionNo = null): Collection
    {
        abort_unless(auth()->user()?->can('books.copies'), 403);

        if (! Entitlements::hasModule('books')) {
            throw new BusinessRuleException('Books are not enabled for this library.');
        }
        if ($book->isEbook()) {
            throw new BusinessRuleException('An e-book has no physical copies.');
        }
        if ($book->isArchived()) {
            throw new BusinessRuleException('Restore this book before adding copies.');
        }
        if ($count < 1 || $count > self::MAX_AT_ONCE) {
            throw new BusinessRuleException('Add between 1 and '.self::MAX_AT_ONCE.' copies at a time.');
        }

        $accessionNo = filled($accessionNo) ? trim($accessionNo) : null;
        if ($accessionNo !== null) {
            if ($count !== 1) {
                throw new BusinessRuleException('A fixed accession number can only be given to one copy.');
            }
            if (mb_strlen($accessionNo) > 40 || ! preg_match('/^[\x21-\x7E]+$/', $accessionNo)) {
                throw new BusinessRuleException('The accession number must be up to 40 letters, digits or symbols, with no spaces.');
            }
        }

        $price = $details['purchase_price'] ?? $book->purchase_price;
        if (filled($price) && (! is_numeric($price) || (float) $price < 0 || (float) $price > 9999999)) {
            throw new BusinessRuleException('Enter a valid purchase price.');
        }

        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) LibrarySettings::get('accession_prefix'))) ?: 'ACC';
        $width = min(10, max(3, (int) LibrarySettings::get('accession_padding')));

        $copies = DB::transaction(function () use ($book, $count, $details, $accessionNo, $price, $prefix, $width) {
            // Serialise per title so two staff adding at once cannot interleave odd numbers.
            Book::query()->whereKey($book->id)->lockForUpdate()->firstOrFail();

            $made = collect();
            for ($i = 0; $i < $count; $i++) {
                $number = $accessionNo ?? $this->nextFreeNumber($prefix, $width);
                if (BookCopy::where('accession_no', $number)->orWhere('barcode', $number)->exists()) {
                    throw new BusinessRuleException("The accession number {$number} is already used.");
                }

                $copy = BookCopy::create([
                    'book_id' => $book->id, 'accession_no' => $number, 'barcode' => $number,
                    'location' => filled($details['location'] ?? null) ? mb_substr(trim($details['location']), 0, 100) : null,
                    'purchase_price' => filled($price) ? number_format((float) $price, 2, '.', '') : null,
                    'supplier' => filled($details['supplier'] ?? $book->supplier) ? mb_substr(trim($details['supplier'] ?? $book->supplier), 0, 150) : null,
                    'purchased_on' => filled($details['purchased_on'] ?? null) ? $details['purchased_on'] : $book->purchased_on,
                    'note' => filled($details['note'] ?? null) ? mb_substr(trim($details['note']), 0, 255) : null,
                ]);
                BookCopyEvent::log($copy, 'acquired', null, BookCopy::AVAILABLE, $details['note'] ?? null);
                $made->push($copy);
            }

            AuditLog::record('book.copies_added', $book, ['title' => $book->title, 'count' => $count, 'first' => $made->first()->accession_no, 'last' => $made->last()->accession_no]);

            return $made;
        });

        return $copies;
    }

    /** The next number from the library's counter that nobody has typed in by hand already. */
    protected function nextFreeNumber(string $prefix, int $width): string
    {
        for ($try = 0; $try < 100; $try++) {
            $number = NextNumber::for('accession', $prefix, $width);
            if (! BookCopy::where('accession_no', $number)->orWhere('barcode', $number)->exists()) {
                return $number;
            }
        }

        throw new BusinessRuleException('Could not find a free accession number. Check the accession prefix in Settings.');
    }
}
