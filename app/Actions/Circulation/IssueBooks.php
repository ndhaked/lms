<?php

namespace App\Actions\Circulation;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\BookCopy;
use App\Models\BookCopyEvent;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Support\CirculationRules;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use App\Support\NextNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class IssueBooks
{
    public const MAX_PER_TRANSACTION = 20;

    /**
     * Checks everything that must be true before this member may borrow. Returns null when they may, or the reason.
     * Used by the issue screen to show a member's status as soon as they are picked.
     */
    public static function blockReason(Student $student): ?string
    {
        if ($student->status !== Student::ACTIVE) {
            return 'This member is '.$student->status.' and cannot borrow.';
        }
        if (LibrarySettings::get('circulation_require_membership') && ! StudentMembership::current()->where('student_id', $student->id)->exists()) {
            return 'This member has no running membership. Renew it before issuing books.';
        }

        $limit = (float) LibrarySettings::get('block_issue_fines_over');
        if ($limit > 0) {
            $unpaid = Fine::where('student_id', $student->id)->where('status', Fine::PENDING)->get()->sum(fn ($f) => (float) $f->outstanding());
            if ($unpaid >= $limit) {
                return 'Unpaid fines of ₹'.number_format($unpaid, 2).' must be cleared first.';
            }
        }

        return null;
    }

    /**
     * Issues the copies with the given barcodes to one member in a single transaction (all or nothing).
     *
     * @param  list<string>  $barcodes  scanned or typed barcodes / accession numbers
     */
    public function __invoke(Student $student, array $barcodes, ?string $notes = null): Loan
    {
        abort_unless(auth()->user()?->can('circulation.issue'), 403);

        if (! Entitlements::hasModule('books')) {
            throw new BusinessRuleException('Books are not enabled for this library.');
        }

        $barcodes = array_values(array_unique(array_filter(array_map(fn ($b) => trim((string) $b), $barcodes), fn ($b) => $b !== '')));
        if (! $barcodes) {
            throw new BusinessRuleException('Scan or enter at least one book.');
        }
        if (count($barcodes) > self::MAX_PER_TRANSACTION) {
            throw new BusinessRuleException('Issue at most '.self::MAX_PER_TRANSACTION.' books at a time.');
        }

        try {
            $loan = DB::transaction(function () use ($student, $barcodes, $notes) {
                // One issue at a time per member, so the limit check cannot be beaten by two tabs.
                $student = Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();

                if ($reason = self::blockReason($student)) {
                    throw new BusinessRuleException($reason);
                }

                $rule = CirculationRules::rule($student->member_type);
                $open = LoanItem::open()->where('student_id', $student->id)->get(['id', 'book_id']);
                if ($open->count() + count($barcodes) > $rule['max_items']) {
                    throw new BusinessRuleException("{$student->name} can borrow {$rule['max_items']} book".($rule['max_items'] === 1 ? '' : 's').' at a time and already has '.$open->count().'.');
                }

                $copies = BookCopy::with('book')->where(fn ($q) => $q->whereIn('barcode', $barcodes)->orWhereIn('accession_no', $barcodes))->lockForUpdate()->get();
                $byCode = [];
                foreach ($copies as $c) {
                    $byCode[$c->barcode] = $c;
                    $byCode[$c->accession_no] = $c;
                }

                $missing = array_values(array_filter($barcodes, fn ($b) => ! isset($byCode[$b])));
                if ($missing) {
                    throw new BusinessRuleException('No book found for: '.implode(', ', $missing).'.');
                }

                $chosen = collect($barcodes)->map(fn ($b) => $byCode[$b])->unique('id')->values();
                $titles = $open->pluck('book_id')->all();

                foreach ($chosen as $copy) {
                    $label = "{$copy->book->title} ({$copy->accession_no})";
                    $heldForThisMember = $copy->status === BookCopy::RESERVED && Reservations::readyFor($copy, $student) !== null;
                    if ($copy->status !== BookCopy::AVAILABLE && ! $heldForThisMember) {
                        throw new BusinessRuleException($copy->status === BookCopy::RESERVED
                            ? "{$label} is being held for another member."
                            : "{$label} cannot be issued: it is ".strtolower(BookCopy::STATUSES[$copy->status] ?? $copy->status).'.');
                    }
                    if ($copy->book->isArchived() || $copy->book->isEbook()) {
                        throw new BusinessRuleException("{$label} is not available for issue.");
                    }
                    if (LibrarySettings::get('block_same_title_twice') && in_array($copy->book_id, $titles, true)) {
                        throw new BusinessRuleException("{$student->name} already has “{$copy->book->title}”.");
                    }
                    $titles[] = $copy->book_id;
                }

                $loan = Loan::create([
                    'loan_no' => NextNumber::for('loan', preg_replace('/[^A-Za-z0-9]/', '', (string) LibrarySettings::get('loan_no_prefix')) ?: 'LN'),
                    'student_id' => $student->id, 'issued_by' => auth()->id(), 'issued_at' => now(),
                    'notes' => filled($notes) ? mb_substr(trim($notes), 0, 500) : null,
                ]);

                $due = CirculationRules::dueDate(today(), $rule['loan_days']);
                foreach ($chosen as $copy) {
                    LoanItem::create([
                        'loan_id' => $loan->id, 'student_id' => $student->id, 'book_id' => $copy->book_id, 'book_copy_id' => $copy->id,
                        'issued_at' => now(), 'due_on' => $due->toDateString(),
                    ]);
                    $was = $copy->status;
                    $copy->forceFill(['status' => BookCopy::ISSUED])->save();
                    BookCopyEvent::log($copy, 'issued', $was, BookCopy::ISSUED, "{$loan->loan_no} · {$student->name}");
                }

                // Their reservations for these titles are done; a copy held for them that they did not take moves on.
                app(Reservations::class)->fulfil($student, $chosen->pluck('book_id')->all(), $chosen->pluck('id')->all());

                AuditLog::record('circulation.issued', $loan, ['loan' => $loan->loan_no, 'student' => $student->name, 'books' => $chosen->pluck('accession_no')->all(), 'due_on' => $due->toDateString()]);

                return $loan->load('items.book', 'items.copy', 'student');
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('One of these books was just issued to someone else. Check and try again.');
        }

        return $loan;
    }
}
