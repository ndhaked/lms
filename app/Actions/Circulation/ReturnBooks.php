<?php

namespace App\Actions\Circulation;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\BookCopy;
use App\Models\BookCopyEvent;
use App\Models\Fine;
use App\Models\LoanItem;
use App\Support\CirculationRules;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use App\Support\NextNumber;
use App\Support\Notifier;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

class ReturnBooks
{
    public const GOOD = 'good';
    public const DAMAGED = 'damaged';
    public const LOST = 'lost';

    /**
     * Records the return of one or more books (partial returns of a multi-book loan are just fewer items).
     * Each entry: ['item' => loan item id] or ['barcode' => scanned code], plus 'condition' (good | damaged | lost),
     * and for damaged books an optional 'fee'. The return gets one receipt number; fines are created for lateness,
     * for lost copies and for damage.
     *
     * @param  list<array{item?: int, barcode?: string, condition?: string, fee?: string|float|null}>  $returns
     * @return array{receipt_no: string, items: list<LoanItem>, fines: list<Fine>}
     */
    public function __invoke(array $returns): array
    {
        abort_unless(auth()->user()?->can('circulation.return'), 403);

        if (! Entitlements::hasModule('books')) {
            throw new BusinessRuleException('Books are not enabled for this library.');
        }
        if (! $returns) {
            throw new BusinessRuleException('Choose at least one book to return.');
        }

        foreach ($returns as $r) {
            if (($r['condition'] ?? self::GOOD) !== self::GOOD) {
                abort_unless(auth()->user()?->can('circulation.manage'), 403);   // lost / damaged handling needs more authority
            }
        }

        $freed = [];
        $result = DB::transaction(function () use ($returns, &$freed) {
            $receipt = NextNumber::for('return', 'RT');
            $items = [];
            $fines = [];
            $seen = [];

            foreach ($returns as $r) {
                $condition = $r['condition'] ?? self::GOOD;
                if (! in_array($condition, [self::GOOD, self::DAMAGED, self::LOST], true)) {
                    throw new BusinessRuleException('Choose good, damaged or lost for each book.');
                }

                $item = $this->find($r);
                if (isset($seen[$item->id])) {
                    continue;
                }
                $seen[$item->id] = true;

                $item = LoanItem::with(['copy', 'book', 'student'])->whereKey($item->id)->lockForUpdate()->firstOrFail();
                if (! $item->isOpen()) {
                    throw new BusinessRuleException("“{$item->book->title}” has already been returned.");
                }

                $copy = BookCopy::whereKey($item->book_copy_id)->lockForUpdate()->firstOrFail();
                $student = $item->student;
                $rule = CirculationRules::rule($student->member_type);

                $late = CirculationRules::overdueDays($item->due_on, now(), $rule['grace_days']);
                $newStatus = match ($condition) { self::LOST => BookCopy::LOST, self::DAMAGED => BookCopy::DAMAGED, default => BookCopy::AVAILABLE };

                $item->forceFill([
                    'status' => $condition === self::LOST ? LoanItem::LOST : LoanItem::RETURNED, 'returned_at' => now(), 'returned_by' => auth()->id(),
                    'return_receipt_no' => $receipt, 'return_condition' => $condition,
                ])->save();

                $copy->forceFill(['status' => $newStatus])->save();
                if ($newStatus === BookCopy::AVAILABLE) {
                    $freed[] = $copy->id;
                }
                BookCopyEvent::log($copy, $condition === self::LOST ? 'lost_on_loan' : 'returned', BookCopy::ISSUED, $newStatus, $student->name.($late > 0 ? " · {$late} day(s) late" : ''));

                if ($condition !== self::LOST && $late > 0) {
                    $amount = CirculationRules::fineFor($rule, $late);
                    if (bccomp($amount, '0.00', 2) > 0) {
                        $fines[] = $this->fine($item, 'overdue', $amount, $late, "{$late} day(s) late: {$item->book->title}");
                    }
                }
                if ($condition === self::LOST) {
                    $price = (string) ($copy->purchase_price ?? $item->book->purchase_price ?? '0');
                    $amount = bcadd(bcmul($price, (string) LibrarySettings::get('lost_fine_multiplier'), 2), (string) LibrarySettings::get('lost_fine_extra'), 2);
                    if (bccomp($amount, '0.00', 2) > 0) {
                        $fines[] = $this->fine($item, 'lost', $amount, null, "Lost: {$item->book->title} ({$copy->accession_no})");
                    }
                }
                if ($condition === self::DAMAGED) {
                    $fee = $r['fee'] ?? null;
                    if (filled($fee)) {
                        if (! is_numeric($fee) || (float) $fee < 0 || (float) $fee > 9999999) {
                            throw new BusinessRuleException('Enter a valid damage fee.');
                        }
                        if ((float) $fee > 0) {
                            $fines[] = $this->fine($item, 'damaged', number_format((float) $fee, 2, '.', ''), null, "Damaged: {$item->book->title} ({$copy->accession_no})");
                        }
                    }
                }

                $items[] = $item;
            }

            AuditLog::record('circulation.returned', null, [
                'receipt' => $receipt, 'books' => collect($items)->map(fn ($i) => $i->copy->accession_no)->all(),
                'fines' => collect($fines)->sum(fn ($f) => (float) $f->amount),
            ]);

            return ['receipt_no' => $receipt, 'items' => $items, 'fines' => $fines];
        });

        foreach ($freed as $copyId) {
            app(Reservations::class)->offerCopy(BookCopy::findOrFail($copyId));      // the first member waiting for this title gets it
        }

        $this->alertRepeatOffenders($result['items']);

        return $result;
    }

    protected function find(array $r): LoanItem
    {
        if (isset($r['item'])) {
            return LoanItem::open()->whereKey((int) $r['item'])->first() ?? throw new BusinessRuleException('That book is not out on loan.');
        }

        $code = trim((string) ($r['barcode'] ?? ''));
        $copy = BookCopy::where('barcode', $code)->orWhere('accession_no', $code)->first();
        if (! $copy) {
            throw new BusinessRuleException("No book found for “{$code}”.");
        }

        return LoanItem::open()->where('book_copy_id', $copy->id)->first() ?? throw new BusinessRuleException("{$copy->accession_no} is not out on loan.");
    }

    protected function fine(LoanItem $item, string $type, string $amount, ?int $days, string $note): Fine
    {
        return Fine::create(['student_id' => $item->student_id, 'loan_item_id' => $item->id, 'type' => $type, 'days' => $days, 'amount' => $amount, 'note' => mb_substr($note, 0, 255), 'created_by' => auth()->id()]);
    }

    /** Tell the library when the same member keeps returning books late. */
    protected function alertRepeatOffenders(array $items): void
    {
        $threshold = (int) LibrarySettings::get('repeat_overdue_alert');
        if ($threshold < 1) {
            return;
        }

        foreach (collect($items)->filter(fn ($i) => $i->due_on->lt(today()))->pluck('student_id')->unique() as $studentId) {
            $count = LoanItem::where('student_id', $studentId)->where('returned_at', '>=', now()->subDays(90))->whereRaw('date(returned_at) > due_on')->count();
            if ($count >= $threshold) {
                $name = collect($items)->firstWhere('student_id', $studentId)->student->name;
                Notifier::library(Tenancy::library(), 'circulation.manage', 'circulation.repeat_overdue', "{$name} keeps returning books late",
                    "{$count} late returns in the last 90 days.", route('app.circulation.loans', ['library' => Tenancy::slug()]), "repeat-overdue:{$studentId}:".today()->toDateString());
            }
        }
    }
}
