<?php

namespace App\Actions\Books;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\InventoryAudit;
use App\Models\InventoryAuditItem;
use App\Support\Entitlements;
use App\Support\Tenancy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Stock-taking: staff walk the shelves scanning every copy; at the end the register is compared with what was found.
 * Copies the register says are on the shelf but were never scanned are "missing"; copies that turn up although the
 * register says they are out, lost or written off are "unexpected". Nothing changes in the register until the audit
 * is closed, and then only what the person closing it chooses.
 */
class StockTake
{
    /** Copies the register expects to find on the shelf. */
    public const ON_SHELF = [BookCopy::AVAILABLE, BookCopy::RESERVED, BookCopy::DAMAGED];

    protected function authorizeAudit(): void
    {
        abort_unless(auth()->user()?->can('books.audit'), 403);

        if (! Entitlements::hasModule('books')) {
            throw new BusinessRuleException('Books are not enabled for this library.');
        }
    }

    public function start(string $name, string $scopeType = 'all', ?string $scopeValue = null): InventoryAudit
    {
        $this->authorizeAudit();

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new BusinessRuleException('Give the stock-take a name (up to 120 characters).');
        }
        if (! in_array($scopeType, ['all', 'category', 'shelf'], true)) {
            throw new BusinessRuleException('Choose what to count.');
        }

        $categoryIds = null;
        if ($scopeType === 'category') {
            $category = BookCategory::find((int) $scopeValue) ?? throw new BusinessRuleException('Choose a category.');
            $categoryIds = BookCategory::where('id', $category->id)->orWhere('parent_id', $category->id)->pluck('id');   // a main category includes its subcategories
        }
        if ($scopeType === 'shelf' && blank($scopeValue)) {
            throw new BusinessRuleException('Enter the shelf to count.');
        }

        try {
            return DB::transaction(function () use ($name, $scopeType, $scopeValue, $categoryIds) {
                $audit = InventoryAudit::create([
                    'name' => $name, 'scope_type' => $scopeType, 'scope_value' => $scopeType === 'all' ? null : trim((string) $scopeValue),
                    'started_by' => auth()->id(), 'started_at' => now(),
                ]);

                $libraryId = Tenancy::currentId();
                $expected = 0;
                BookCopy::query()->whereIn('book_copies.status', self::ON_SHELF)
                    ->whereHas('book', function ($b) use ($scopeType, $scopeValue, $categoryIds) {
                        $b->where('type', 'physical')
                            ->when($scopeType === 'category', fn ($q) => $q->whereIn('category_id', $categoryIds))
                            ->when($scopeType === 'shelf', fn ($q) => $q->where('shelf', trim((string) $scopeValue)));
                    })
                    ->select(['book_copies.id', 'book_copies.status'])
                    ->chunkById(1000, function ($copies) use ($audit, $libraryId, &$expected) {
                        DB::table('inventory_audit_items')->insert($copies->map(fn ($c) => [
                            'library_id' => $libraryId, 'inventory_audit_id' => $audit->id, 'book_copy_id' => $c->id, 'expected' => true, 'status_at_start' => $c->status,
                        ])->all());
                        $expected += $copies->count();
                    }, 'book_copies.id', 'id');

                $audit->forceFill(['expected_count' => $expected])->save();
                AuditLog::record('stocktake.started', $audit, ['name' => $name, 'scope' => $audit->scopeLabel(), 'expected' => $expected]);

                if ($expected === 0) {
                    throw new BusinessRuleException('There are no copies to count for that choice.');
                }

                return $audit;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('A stock-take is already open. Finish or cancel it first.');
        }
    }

    /**
     * Counts one scanned code.
     *
     * @return array{result: string, message: string, copy: ?BookCopy}  result: ok | already | unexpected | outside | unknown
     */
    public function scan(InventoryAudit $audit, string $code): array
    {
        $this->authorizeAudit();

        $code = trim($code);
        if ($code === '') {
            throw new BusinessRuleException('Scan a barcode.');
        }

        return DB::transaction(function () use ($audit, $code) {
            $audit = InventoryAudit::whereKey($audit->id)->lockForUpdate()->firstOrFail();
            if (! $audit->isOpen()) {
                throw new BusinessRuleException('This stock-take is closed.');
            }

            $copy = BookCopy::with('book')->where('barcode', $code)->orWhere('accession_no', $code)->first();
            if (! $copy) {
                $codes = array_slice(array_values(array_unique(array_merge((array) $audit->unknown_codes, [mb_substr($code, 0, 60)]))), 0, 200);
                $audit->forceFill(['unknown_codes' => $codes])->save();

                return ['result' => 'unknown', 'message' => "“{$code}” is not a book in this library.", 'copy' => null];
            }

            $label = "{$copy->book->title} ({$copy->accession_no})";
            $item = InventoryAuditItem::where('inventory_audit_id', $audit->id)->where('book_copy_id', $copy->id)->lockForUpdate()->first();

            if ($item && $item->scanned_at) {
                return ['result' => 'already', 'message' => "{$label} was already counted.", 'copy' => $copy];
            }

            if ($item) {
                $item->forceFill(['scanned_at' => now(), 'scanned_by' => auth()->id()])->save();
                $audit->increment('found_count');

                return ['result' => 'ok', 'message' => "{$label} counted.", 'copy' => $copy];
            }

            // Not on the list. Copies the register already shows as shelved but outside this count are simply out of scope.
            InventoryAuditItem::create(['inventory_audit_id' => $audit->id, 'book_copy_id' => $copy->id, 'expected' => false, 'status_at_start' => $copy->status, 'scanned_at' => now(), 'scanned_by' => auth()->id()]);

            if (in_array($copy->status, self::ON_SHELF, true)) {
                return ['result' => 'outside', 'message' => "{$label} is not part of this count.", 'copy' => $copy];
            }

            $audit->increment('unexpected_count');
            $why = match ($copy->status) {
                BookCopy::ISSUED => 'The register says it is issued to a member. Check the loan.',
                BookCopy::LOST => 'The register says it is lost. It has been found.',
                BookCopy::WITHDRAWN => 'The register says it was withdrawn.',
                default => 'The register does not expect it on the shelf.',
            };

            return ['result' => 'unexpected', 'message' => "{$label}: {$why}", 'copy' => $copy];
        });
    }

    /** Copies expected but not scanned. */
    public static function missing(InventoryAudit $audit)
    {
        return InventoryAuditItem::with('copy.book')->where('inventory_audit_id', $audit->id)->where('expected', true)->whereNull('scanned_at');
    }

    /** Copies that turned up although the register says they should not be on the shelf. */
    public static function unexpected(InventoryAudit $audit)
    {
        return InventoryAuditItem::with('copy.book')->where('inventory_audit_id', $audit->id)->where('expected', false)->whereNotNull('scanned_at')
            ->whereNotIn('status_at_start', self::ON_SHELF);
    }

    /**
     * Closes the stock-take. Optionally records the result in the register: copies still on the shelf per the register
     * but not found become Lost, and copies that were Lost but have been found become Available again.
     *
     * @return array{missing: int, marked_lost: int, found_again: int, skipped: int}
     */
    public function complete(InventoryAudit $audit, bool $markMissingLost = false, bool $markFoundAvailable = false, ?string $note = null): array
    {
        $this->authorizeAudit();
        if ($markMissingLost || $markFoundAvailable) {
            abort_unless(auth()->user()?->can('books.copies'), 403);
        }

        $audit = DB::transaction(function () use ($audit, $note) {
            $a = InventoryAudit::whereKey($audit->id)->lockForUpdate()->firstOrFail();
            if (! $a->isOpen()) {
                throw new BusinessRuleException('This stock-take is already closed.');
            }

            $missing = self::missing($a)->count();
            $a->forceFill(['status' => InventoryAudit::COMPLETED, 'closed_by' => auth()->id(), 'closed_at' => now(), 'missing_count' => $missing,
                'note' => filled($note) ? mb_substr(trim($note), 0, 500) : null])->save();
            AuditLog::record('stocktake.completed', $a, ['name' => $a->name, 'expected' => $a->expected_count, 'found' => $a->found_count, 'missing' => $missing, 'unexpected' => $a->unexpected_count]);

            return $a;
        });

        $out = ['missing' => $audit->missing_count, 'marked_lost' => 0, 'found_again' => 0, 'skipped' => 0];
        $change = app(ChangeCopyStatus::class);

        if ($markMissingLost) {
            self::missing($audit)->chunkById(500, function ($items) use ($change, $audit, &$out) {
                foreach ($items as $item) {
                    try {
                        // only copies still sitting as "available" can be written off here; anything handled since is left alone
                        if ($item->copy->status !== BookCopy::AVAILABLE) {
                            $out['skipped']++;

                            continue;
                        }
                        $change($item->copy, BookCopy::LOST, "Missing in stock-take: {$audit->name}");
                        $out['marked_lost']++;
                    } catch (BusinessRuleException) {
                        $out['skipped']++;
                    }
                }
            });
        }

        if ($markFoundAvailable) {
            self::unexpected($audit)->where('status_at_start', BookCopy::LOST)->chunkById(500, function ($items) use ($change, $audit, &$out) {
                foreach ($items as $item) {
                    try {
                        if ($item->copy->status === BookCopy::LOST) {
                            $change($item->copy, BookCopy::AVAILABLE, "Found in stock-take: {$audit->name}");
                            $out['found_again']++;
                        }
                    } catch (BusinessRuleException) {
                        $out['skipped']++;
                    }
                }
            });
        }

        if ($out['marked_lost'] || $out['found_again']) {
            AuditLog::record('stocktake.applied', $audit, $out);
        }

        return $out;
    }

    public function cancel(InventoryAudit $audit): InventoryAudit
    {
        $this->authorizeAudit();

        return DB::transaction(function () use ($audit) {
            $a = InventoryAudit::whereKey($audit->id)->lockForUpdate()->firstOrFail();
            if (! $a->isOpen()) {
                throw new BusinessRuleException('This stock-take is already closed.');
            }

            $a->forceFill(['status' => InventoryAudit::CANCELLED, 'closed_by' => auth()->id(), 'closed_at' => now()])->save();
            AuditLog::record('stocktake.cancelled', $a, ['name' => $a->name]);

            return $a;
        });
    }
}
