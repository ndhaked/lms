<?php

namespace App\Actions\Circulation;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\BookCopy;
use App\Models\BookCopyEvent;
use App\Models\Fine;
use App\Models\LoanItem;
use App\Models\Reservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Authorised corrections: undo a mistaken issue, or a mistaken return. Nothing is deleted; both are recorded. */
class ReverseCirculation
{
    public function issue(LoanItem $item, string $reason): LoanItem
    {
        abort_unless(auth()->user()?->can('circulation.manage'), 403);
        $this->needReason($reason);

        $item = DB::transaction(function () use ($item, $reason) {
            $item = LoanItem::with(['copy', 'book', 'student'])->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if (! $item->isOpen()) {
                throw new BusinessRuleException('Only a book that is still out on loan can have its issue reversed.');
            }

            $item->forceFill(['status' => LoanItem::REVERSED])->save();
            $item->copy->forceFill(['status' => BookCopy::AVAILABLE])->save();
            BookCopyEvent::log($item->copy, 'issue_reversed', BookCopy::ISSUED, BookCopy::AVAILABLE, $reason);
            AuditLog::record('circulation.issue_reversed', $item, ['book' => $item->book->title, 'student' => $item->student->name, 'reason' => trim($reason)]);

            return $item;
        });

        app(Reservations::class)->offerCopy($item->copy);   // the copy is free again: offer it to whoever is waiting

        return $item;
    }

    public function return(LoanItem $item, string $reason): LoanItem
    {
        abort_unless(auth()->user()?->can('circulation.manage'), 403);
        $this->needReason($reason);

        try {
            return DB::transaction(function () use ($item, $reason) {
                $item = LoanItem::with(['copy', 'book', 'student'])->whereKey($item->id)->lockForUpdate()->firstOrFail();

                if (! in_array($item->status, [LoanItem::RETURNED, LoanItem::LOST], true)) {
                    throw new BusinessRuleException('Only a returned book can have its return reversed.');
                }

                $copy = BookCopy::whereKey($item->book_copy_id)->lockForUpdate()->firstOrFail();
                $expected = match ($item->return_condition) { 'lost' => BookCopy::LOST, 'damaged' => BookCopy::DAMAGED, default => BookCopy::AVAILABLE };

                // The return may have put the copy on hold for the next member in line: put that member back in the queue.
                $hold = $copy->status === BookCopy::RESERVED ? Reservation::where('book_copy_id', $copy->id)->where('status', Reservation::READY)->first() : null;
                if ($hold && $expected === BookCopy::AVAILABLE) {
                    $hold->forceFill(['status' => Reservation::WAITING, 'book_copy_id' => null, 'ready_at' => null, 'expires_on' => null])->save();
                    $copy->status = BookCopy::AVAILABLE;
                }

                if ($copy->status !== $expected) {
                    throw new BusinessRuleException('The copy has been handled since it came back (now '.strtolower(BookCopy::STATUSES[$copy->status] ?? $copy->status).'), so this return cannot be reversed.');
                }

                $fines = Fine::where('loan_item_id', $item->id)->get();
                if ($fines->contains(fn ($f) => (float) $f->paid_amount > 0)) {
                    throw new BusinessRuleException('A fine from this return was already collected. Reverse that with the library owner first.');
                }

                foreach ($fines as $fine) {
                    $fine->delete();   // unpaid fines created by the mistaken return go away with it
                }

                $item->forceFill(['status' => LoanItem::ISSUED, 'returned_at' => null, 'returned_by' => null, 'return_receipt_no' => null, 'return_condition' => null])->save();
                $copy->forceFill(['status' => BookCopy::ISSUED])->save();
                BookCopyEvent::log($copy, 'return_reversed', $expected, BookCopy::ISSUED, $reason);
                AuditLog::record('circulation.return_reversed', $item, ['book' => $item->book->title, 'student' => $item->student->name, 'fines_removed' => $fines->count(), 'reason' => trim($reason)]);

                return $item;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('This copy was issued again since it came back, so the return cannot be reversed.');
        }
    }

    protected function needReason(string $reason): void
    {
        if (blank($reason)) {
            throw new BusinessRuleException('A reason is required for a reversal.');
        }
    }
}
