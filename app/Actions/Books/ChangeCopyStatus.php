<?php

namespace App\Actions\Books;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\BookCopy;
use App\Models\BookCopyEvent;
use Illuminate\Support\Facades\DB;

/** Manual stock changes for one copy. Issuing and returning are done in circulation, never here. */
class ChangeCopyStatus
{
    /** from => allowed targets */
    public const TRANSITIONS = [
        BookCopy::AVAILABLE => [BookCopy::DAMAGED, BookCopy::LOST, BookCopy::WITHDRAWN],
        BookCopy::DAMAGED => [BookCopy::AVAILABLE, BookCopy::LOST, BookCopy::WITHDRAWN],
        BookCopy::LOST => [BookCopy::AVAILABLE, BookCopy::WITHDRAWN],     // found again, or written off
        BookCopy::RESERVED => [],                                          // held for a reservation: cancel the reservation instead
        BookCopy::ISSUED => [],                                            // must be returned first
        BookCopy::WITHDRAWN => [],                                         // disposal is final; the history stays
    ];

    public const NEEDS_NOTE = [BookCopy::DAMAGED, BookCopy::LOST, BookCopy::WITHDRAWN];

    public function __invoke(BookCopy $copy, string $to, ?string $note = null): BookCopy
    {
        abort_unless(auth()->user()?->can('books.copies'), 403);

        $note = filled($note) ? mb_substr(trim($note), 0, 500) : null;
        if (in_array($to, self::NEEDS_NOTE, true) && $note === null) {
            throw new BusinessRuleException('Say why (for the history).');
        }

        $result = DB::transaction(function () use ($copy, $to, $note) {
            $copy = BookCopy::query()->whereKey($copy->id)->lockForUpdate()->firstOrFail();

            if ($copy->status === BookCopy::ISSUED) {
                throw new BusinessRuleException('This copy is issued to a member. Return it first.');
            }
            if (! in_array($to, self::TRANSITIONS[$copy->status] ?? [], true)) {
                throw new BusinessRuleException('A '.strtolower(BookCopy::STATUSES[$copy->status] ?? $copy->status).' copy cannot be marked '.strtolower(BookCopy::STATUSES[$to] ?? $to).'.');
            }

            $from = $copy->status;
            $copy->status = $to;
            $copy->withdrawn_on = $to === BookCopy::WITHDRAWN ? today() : null;
            $copy->save();

            BookCopyEvent::log($copy, $to === BookCopy::WITHDRAWN ? 'withdrawn' : 'status_changed', $from, $to, $note);
            AuditLog::record('book.copy_status_changed', $copy, ['accession_no' => $copy->accession_no, 'from' => $from, 'to' => $to, 'note' => $note]);

            return $copy;
        });

        if ($to === BookCopy::AVAILABLE) {
            app(\App\Actions\Circulation\Reservations::class)->offerCopy($result);      // found or repaired: someone may be waiting
        }

        return $result;
    }
}
