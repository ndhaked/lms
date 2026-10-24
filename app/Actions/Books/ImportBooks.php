<?php

namespace App\Actions\Books;

use App\Exceptions\BusinessRuleException;
use App\Models\Book;
use App\Models\BookCategory;
use App\Support\Isbn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Bulk import of the physical catalogue from CSV / Excel. A first pass (preview) validates every row and says what
 * would happen; the second pass (import) does it row by row, so one bad row never blocks the others.
 * E-book files are uploaded one at a time; a sheet can only list e-books that have an external link.
 */
class ImportBooks
{
    public const MAX_ROWS = 2000;

    public const COLUMNS = [
        'type', 'title', 'isbn', 'author', 'publisher', 'edition', 'language', 'publication_year', 'category', 'subcategory', 'tags', 'description',
        'shelf', 'rack', 'section', 'location', 'copies', 'accession_no', 'purchase_price', 'supplier', 'purchased_on', 'link',
    ];

    /** Header text people commonly use, mapped to our columns. */
    protected const ALIASES = [
        'year' => 'publication_year', 'publication year' => 'publication_year', 'price' => 'purchase_price', 'purchase price' => 'purchase_price',
        'no. of copies' => 'copies', 'number of copies' => 'copies', 'qty' => 'copies', 'quantity' => 'copies', 'accession' => 'accession_no',
        'accession no' => 'accession_no', 'accession number' => 'accession_no', 'url' => 'link', 'ebook link' => 'link', 'purchase date' => 'purchased_on',
        'isbn13' => 'isbn', 'isbn-13' => 'isbn', 'isbn10' => 'isbn', 'isbn-10' => 'isbn',
    ];

    /** @return list<array<string, string>> rows keyed by column, in file order */
    public function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw new BusinessRuleException('Upload a .csv or .xlsx file.');
        }

        $reader = $extension === 'csv' ? new CsvReader : new XlsxReader;
        $reader->open($path);

        $rows = [];
        $map = null;
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(fn ($c) => is_object($c) && method_exists($c, 'format') ? $c->format('Y-m-d') : trim((string) $c), $row->toArray());
                    if (! array_filter($cells, fn ($c) => $c !== '')) {
                        continue;
                    }

                    if ($map === null) {
                        $map = [];
                        foreach ($cells as $i => $h) {
                            $h = strtolower(trim(ltrim($h, "\xEF\xBB\xBF")));
                            $h = self::ALIASES[$h] ?? str_replace(' ', '_', $h);
                            if (in_array($h, self::COLUMNS, true)) {
                                $map[$i] = $h;
                            }
                        }
                        if (! in_array('title', $map, true)) {
                            throw new BusinessRuleException('The first row must be column headings, including “title”. Download the template to see them.');
                        }

                        continue;
                    }

                    if (count($rows) >= self::MAX_ROWS) {
                        throw new BusinessRuleException('Too many rows: import at most '.self::MAX_ROWS.' at a time. Split the file and import it in parts.');
                    }
                    $assoc = array_fill_keys(self::COLUMNS, '');
                    foreach ($map as $i => $col) {
                        $assoc[$col] = $cells[$i] ?? '';
                    }
                    $rows[] = $assoc;
                }
                break;   // first sheet only
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /**
     * Validate every row and say what an import would do.
     *
     * @return list<array{line: int, status: string, message: string, row: array<string, string>}> status: new | duplicate | error
     */
    public function preview(array $rows): array
    {
        $result = [];
        $seen = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2;   // line 1 is the heading
            try {
                $row = $this->normalize($row);
                $key = $this->dupKey($row);
                $existing = isset($seen[$key]) ? true : $this->findExisting($row);
                $seen[$key] = true;

                $result[] = ['line' => $line, 'row' => $row, 'status' => $existing ? 'duplicate' : 'new',
                    'message' => $existing ? 'Already in the catalogue' : ($row['type'] === Book::EBOOK ? 'New e-book' : 'New title, '.$row['copies'].' cop'.($row['copies'] === 1 ? 'y' : 'ies'))];
            } catch (BusinessRuleException $e) {
                $result[] = ['line' => $line, 'row' => $row, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * @param  string  $onDuplicate  skip | add_copies (add the row's copies to the title that already exists)
     * @return array{created: int, copies: int, skipped: int, errors: list<array{line: int, message: string}>}
     */
    public function import(array $rows, string $onDuplicate = 'skip'): array
    {
        abort_unless(auth()->user()?->can('books.import'), 403);

        $out = ['created' => 0, 'copies' => 0, 'skipped' => 0, 'errors' => []];
        $made = [];   // dup key => Book created earlier in this same file

        foreach ($rows as $i => $row) {
            $line = $i + 2;
            try {
                $row = $this->normalize($row);
                $key = $this->dupKey($row);
                $existing = $made[$key] ?? $this->findExisting($row);

                DB::transaction(function () use ($row, $key, $existing, $onDuplicate, &$out, &$made) {
                    if ($existing) {
                        if ($onDuplicate !== 'add_copies' || $row['type'] === Book::EBOOK || $row['copies'] < 1) {
                            $out['skipped']++;

                            return;
                        }
                        $out['copies'] += app(AddCopies::class)($existing, $row['copies'], $this->copyDetails($row), $row['accession_no'] ?: null)->count();

                        return;
                    }

                    $book = app(SaveBook::class)($this->bookData($row));
                    $made[$key] = $book;
                    $out['created']++;
                    if ($row['type'] === Book::PHYSICAL && $row['copies'] > 0) {
                        $out['copies'] += app(AddCopies::class)($book, $row['copies'], $this->copyDetails($row), $row['accession_no'] ?: null)->count();
                    }
                });
            } catch (BusinessRuleException $e) {
                $out['errors'][] = ['line' => $line, 'message' => $e->getMessage()];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> a cleaned row with typed values, or a BusinessRuleException saying what is wrong */
    protected function normalize(array $row): array
    {
        $row = array_map(fn ($v) => trim((string) $v), $row) + array_fill_keys(self::COLUMNS, '');

        $type = strtolower(str_replace(['-', ' '], '', $row['type']));
        $row['type'] = match ($type) {
            '', 'book', 'physical', 'print' => Book::PHYSICAL,
            'ebook', 'digital' => Book::EBOOK,
            default => throw new BusinessRuleException("Type “{$row['type']}” is not valid: use Book or E-book."),
        };

        if ($row['title'] === '') {
            throw new BusinessRuleException('Title is missing.');
        }

        $row['isbn'] = Isbn::normalize($row['isbn']) ?? '';
        if ($row['isbn'] !== '' && ! Isbn::valid($row['isbn'])) {
            throw new BusinessRuleException("ISBN {$row['isbn']} is not valid.");
        }

        if ($row['publication_year'] !== '' && (! ctype_digit($row['publication_year']) || (int) $row['publication_year'] < 1000 || (int) $row['publication_year'] > (int) today()->year + 1)) {
            throw new BusinessRuleException("Publication year “{$row['publication_year']}” is not valid.");
        }
        if ($row['purchase_price'] !== '') {
            $price = str_replace(',', '', $row['purchase_price']);
            if (! is_numeric($price) || (float) $price < 0) {
                throw new BusinessRuleException("Purchase price “{$row['purchase_price']}” is not valid.");
            }
            $row['purchase_price'] = $price;
        }
        if ($row['purchased_on'] !== '') {
            try {
                $row['purchased_on'] = Carbon::parse($row['purchased_on'])->toDateString();
            } catch (\Throwable) {
                throw new BusinessRuleException("Purchase date “{$row['purchased_on']}” is not a date.");
            }
        }

        if ($row['type'] === Book::EBOOK) {
            if ($row['link'] === '') {
                throw new BusinessRuleException('An e-book row needs a link. Upload e-book files from the E-book form.');
            }
            $row['copies'] = 0;
        } else {
            if ($row['copies'] === '') {
                $row['copies'] = 1;
            }
            if (! ctype_digit((string) $row['copies']) || (int) $row['copies'] > AddCopies::MAX_AT_ONCE) {
                throw new BusinessRuleException('Copies must be a whole number from 0 to '.AddCopies::MAX_AT_ONCE.'.');
            }
            $row['copies'] = (int) $row['copies'];
            if ($row['accession_no'] !== '' && $row['copies'] !== 1) {
                throw new BusinessRuleException('An accession number can only be given when copies is 1.');
            }
        }

        return $row;
    }

    protected function dupKey(array $row): string
    {
        return $row['type'].'|'.($row['isbn'] !== '' ? 'isbn:'.$row['isbn'] : 'title:'.mb_strtolower($row['title']).'|'.mb_strtolower($row['author']));
    }

    protected function findExisting(array $row): ?Book
    {
        return Book::where('type', $row['type'])
            ->when($row['isbn'] !== '',
                fn ($q) => $q->where('isbn', $row['isbn']),
                fn ($q) => $q->where('title', $row['title'])->where(fn ($w) => $w->where('author', $row['author'])->when($row['author'] === '', fn ($x) => $x->orWhereNull('author'))))
            ->first();
    }

    protected function bookData(array $row): array
    {
        $categoryId = null;
        if ($row['category'] !== '') {
            $parent = BookCategory::firstOrCreate(['parent_id' => null, 'name' => mb_substr($row['category'], 0, 100)]);
            $categoryId = $parent->id;
            if ($row['subcategory'] !== '') {
                $categoryId = BookCategory::firstOrCreate(['parent_id' => $parent->id, 'name' => mb_substr($row['subcategory'], 0, 100)])->id;
            }
        }

        return ['type' => $row['type'], 'title' => $row['title'], 'isbn' => $row['isbn'], 'author' => $row['author'], 'publisher' => $row['publisher'], 'edition' => $row['edition'],
            'language' => $row['language'], 'publication_year' => $row['publication_year'], 'category_id' => $categoryId, 'tags' => $row['tags'], 'description' => $row['description'],
            'shelf' => $row['shelf'], 'rack' => $row['rack'], 'section' => $row['section'], 'location' => $row['location'],
            'purchase_price' => $row['purchase_price'], 'supplier' => $row['supplier'], 'purchased_on' => $row['purchased_on'], 'external_url' => $row['link']];
    }

    protected function copyDetails(array $row): array
    {
        return ['purchase_price' => $row['purchase_price'] ?: null, 'supplier' => $row['supplier'] ?: null, 'purchased_on' => $row['purchased_on'] ?: null, 'location' => $row['location'] ?: null];
    }
}
