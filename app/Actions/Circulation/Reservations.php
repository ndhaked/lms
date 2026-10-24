<?php

namespace App\Actions\Circulation;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookCopyEvent;
use App\Models\LoanItem;
use App\Models\Reservation;
use App\Models\Student;
use App\Models\User;
use App\Support\CirculationRules;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use App\Support\Notifier;
use App\Support\Tenancy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Reservations: members queue for a title that has no copy on the shelf. A returned copy is held for the first
 * member in the queue ("ready") until their collection date; then it moves on. All rules live here.
 */
class Reservations
{
    // ---------------------------------------------------------------- making & cancelling

    /** Why this member cannot reserve this title now, or null if they can. */
    public static function blockReason(Student $student, Book $book): ?string
    {
        if (! LibrarySettings::get('reservations_enabled')) {
            return 'Reservations are not used at this library.';
        }
        if ($book->isEbook() || $book->isArchived()) {
            return 'This title cannot be reserved.';
        }
        if ($student->status !== Student::ACTIVE) {
            return 'This member cannot borrow right now.';
        }
        if (Reservation::active()->where('student_id', $student->id)->where('book_id', $book->id)->exists()) {
            return 'Already reserved by this member.';
        }
        if (LoanItem::open()->where('student_id', $student->id)->where('book_id', $book->id)->exists()) {
            return 'This member already has this book.';
        }
        if (BookCopy::where('book_id', $book->id)->where('status', BookCopy::AVAILABLE)->exists()) {
            return 'A copy is on the shelf. It can be borrowed right away.';
        }
        if (! BookCopy::where('book_id', $book->id)->inStock()->exists()) {
            return 'There are no copies of this book to wait for.';
        }

        $max = CirculationRules::rule($student->member_type)['max_reservations'];
        if (Reservation::active()->where('student_id', $student->id)->count() >= $max) {
            return "At most {$max} reservation".($max === 1 ? '' : 's').' at a time.';
        }

        return null;
    }

    public function staff(Student $student, Book $book): Reservation
    {
        abort_unless(auth()->user()?->can('circulation.issue'), 403);

        return $this->create($student, $book, 'staff');
    }

    /** A member reserves for themselves. */
    public function self(Book $book): Reservation
    {
        $user = auth()->user();
        abort_unless($user?->can('self.view'), 403);
        $student = Student::where('user_id', $user->id)->first();
        abort_unless($student, 403);

        return $this->create($student, $book, 'self');
    }

    protected function create(Student $student, Book $book, string $by): Reservation
    {
        if (! Entitlements::hasModule('books')) {
            throw new BusinessRuleException('Books are not enabled for this library.');
        }

        try {
            return DB::transaction(function () use ($student, $book, $by) {
                Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
                $book = Book::query()->whereKey($book->id)->lockForUpdate()->firstOrFail();

                if ($reason = self::blockReason($student, $book)) {
                    throw new BusinessRuleException($reason);
                }
                if ($reason = IssueBooks::blockReason($student)) {
                    throw new BusinessRuleException($reason);
                }

                $r = Reservation::create(['student_id' => $student->id, 'book_id' => $book->id, 'requested_at' => now(), 'created_by' => auth()->id()]);
                AuditLog::record('reservation.created', $r, ['student' => $student->name, 'book' => $book->title, 'by' => $by]);

                return $r;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('Already reserved by this member.');
        }
    }

    /** Staff cancel for a member, or a member cancels their own. Releases a held copy to the next in line. */
    public function cancel(Reservation $reservation, ?string $reason = null): Reservation
    {
        $user = auth()->user();
        $mine = $user?->can('self.view') && Student::where('user_id', $user->id)->whereKey($reservation->student_id)->exists();
        abort_unless($mine || $user?->can('circulation.issue'), 403);

        return $this->close($reservation, Reservation::CANCELLED, filled($reason) ? $reason : ($mine ? 'Cancelled by the member' : 'Cancelled by staff'));
    }

    protected function close(Reservation $reservation, string $status, string $reason): Reservation
    {
        $held = DB::transaction(function () use ($reservation, $status, $reason) {
            $r = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if (! $r->isActive()) {
                throw new BusinessRuleException('This reservation is already closed.');
            }

            $copyId = $r->status === Reservation::READY ? $r->book_copy_id : null;
            $r->forceFill(['status' => $status, 'closed_at' => now(), 'closed_reason' => mb_substr($reason, 0, 255)])->save();
            AuditLog::record("reservation.{$status}", $r, ['student' => Student::find($r->student_id)?->name, 'reason' => $reason]);

            return $copyId;
        });

        if ($held) {
            $this->offerCopy(BookCopy::findOrFail($held), true);
        }

        return $reservation->refresh();
    }

    // ---------------------------------------------------------------- copies coming back

    /**
     * A copy has just become free (returned, found, repaired, an issue was reversed...). If somebody is waiting for the
     * title, hold the copy for the first of them; otherwise it stays on the shelf. Returns the reservation, or null.
     */
    public function offerCopy(BookCopy $copy, bool $wasHeld = false): ?Reservation
    {
        if (! LibrarySettings::get('reservations_enabled')) {
            return $this->release($copy, $wasHeld);
        }

        $reservation = DB::transaction(function () use ($copy) {
            $copy = BookCopy::query()->whereKey($copy->id)->lockForUpdate()->firstOrFail();
            if (! in_array($copy->status, [BookCopy::AVAILABLE, BookCopy::RESERVED], true)) {
                return null;
            }

            // The first member in the queue who is still allowed to borrow gets it; others who no longer qualify are passed over.
            $waiting = Reservation::with('student')->where('book_id', $copy->book_id)->where('status', Reservation::WAITING)->orderBy('requested_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($waiting as $r) {
                if (IssueBooks::blockReason($r->student) !== null) {
                    continue;
                }

                $days = max(1, (int) LibrarySettings::get('reservation_hold_days'));
                $r->forceFill(['status' => Reservation::READY, 'book_copy_id' => $copy->id, 'ready_at' => now(), 'expires_on' => CirculationRules::dueDate(today(), $days)->toDateString()])->save();

                $from = $copy->status;
                $copy->forceFill(['status' => BookCopy::RESERVED])->save();
                BookCopyEvent::log($copy, 'held_for_reservation', $from, BookCopy::RESERVED, $r->student->name);

                return $r;
            }

            return null;
        });

        if (! $reservation) {
            return $this->release($copy, $wasHeld);
        }

        if ($user = $reservation->student->user_id ? User::find($reservation->student->user_id) : null) {
            Notifier::user($user, Tenancy::currentId(), 'reservation.ready', 'Your reserved book is ready',
                "“{$reservation->book->title}” is waiting for you until {$reservation->expires_on->format('d M Y')}.", route('app.my.index', ['library' => Tenancy::slug()]));
        }

        return $reservation;
    }

    /** Nobody is waiting: a copy that was held goes back on the shelf. */
    protected function release(BookCopy $copy, bool $wasHeld): ?Reservation
    {
        if ($wasHeld) {
            $copy = BookCopy::findOrFail($copy->id);
            if ($copy->status === BookCopy::RESERVED) {
                $copy->forceFill(['status' => BookCopy::AVAILABLE])->save();
                BookCopyEvent::log($copy, 'hold_released', BookCopy::RESERVED, BookCopy::AVAILABLE);
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- issuing & expiry

    /** The member borrowed the title: their reservation is done, and a copy held for them (if different) moves on. */
    public function fulfil(Student $student, array $bookIds, array $issuedCopyIds = []): void
    {
        $held = [];
        foreach (Reservation::active()->where('student_id', $student->id)->whereIn('book_id', $bookIds)->get() as $r) {
            if ($r->status === Reservation::READY && $r->book_copy_id && ! in_array($r->book_copy_id, $issuedCopyIds, true)) {
                $held[] = $r->book_copy_id;
            }
            $r->forceFill(['status' => Reservation::FULFILLED, 'closed_at' => now(), 'closed_reason' => 'Borrowed'])->save();
            AuditLog::record('reservation.fulfilled', $r, ['student' => $student->name]);
        }

        foreach ($held as $copyId) {
            $this->offerCopy(BookCopy::findOrFail($copyId), true);
        }
    }

    /** The reservation (if any) holding this copy for this member. */
    public static function readyFor(BookCopy $copy, Student $student): ?Reservation
    {
        return Reservation::where('status', Reservation::READY)->where('book_copy_id', $copy->id)->where('student_id', $student->id)->first();
    }

    /** Reservations that were not collected in time lapse; their copy goes to the next member or back on the shelf. */
    public function expireDue(): int
    {
        $count = 0;

        foreach (Reservation::with(['student', 'book'])->where('status', Reservation::READY)->whereDate('expires_on', '<', today())->get() as $r) {
            try {
                $this->close($r, Reservation::EXPIRED, 'Not collected in time');
                $count++;

                if ($user = $r->student->user_id ? User::find($r->student->user_id) : null) {
                    Notifier::user($user, Tenancy::currentId(), 'reservation.expired', 'A reservation expired', "“{$r->book->title}” was not collected in time.", route('app.my.index', ['library' => Tenancy::slug()]));
                }
            } catch (BusinessRuleException) {
                // closed by someone else in the meantime
            }
        }

        return $count;
    }
}
