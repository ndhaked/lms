<?php

namespace App\Actions\Circulation;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\BookCopyEvent;
use App\Models\LoanItem;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Support\CirculationRules;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use Illuminate\Support\Facades\DB;

class RenewLoanItem
{
    /** Why this item cannot be renewed right now, or null if it can. */
    public static function blockReason(LoanItem $item): ?string
    {
        if (! $item->isOpen()) {
            return 'This book is not out on loan.';
        }

        $student = $item->student ?? Student::find($item->student_id);
        if ($student->status !== Student::ACTIVE) {
            return 'This member cannot borrow right now.';
        }

        $rule = CirculationRules::rule($student->member_type);
        if ($item->renewals >= $rule['max_renewals']) {
            return $rule['max_renewals'] === 0 ? 'Renewals are not allowed.' : "Already renewed {$item->renewals} time(s), the most allowed.";
        }
        if ($item->due_on->lt(today()) && ! LibrarySettings::get('allow_renew_overdue')) {
            return 'This book is overdue. Return it instead.';
        }
        if (LibrarySettings::get('circulation_require_membership') && ! StudentMembership::current()->where('student_id', $student->id)->exists()) {
            return 'The membership has ended. Renew it first.';
        }
        if (self::reservedByOther($item)) {
            return 'Another member is waiting for this title.';
        }

        return null;
    }

    /** Reservations (when that module is used) block a renewal; hook kept here so the rule lives in one place. */
    protected static function reservedByOther(LoanItem $item): bool
    {
        return \App\Models\Reservation::where('book_id', $item->book_id)->where('status', 'waiting')->where('student_id', '!=', $item->student_id)->exists();
    }

    /** Staff renew on a member's behalf. */
    public function staff(LoanItem $item): LoanItem
    {
        abort_unless(auth()->user()?->can('circulation.renew'), 403);

        return $this->renew($item, 'staff');
    }

    /** A member renews their own book from their page (if the library allows it). */
    public function self(LoanItem $item): LoanItem
    {
        $user = auth()->user();
        abort_unless($user?->can('self.view'), 403);
        abort_unless(Student::where('user_id', $user->id)->whereKey($item->student_id)->exists(), 403);

        if (! LibrarySettings::get('student_can_renew')) {
            throw new BusinessRuleException('Please ask the library to renew your book.');
        }

        return $this->renew($item, 'self');
    }

    protected function renew(LoanItem $item, string $by): LoanItem
    {
        if (! Entitlements::hasModule('books')) {
            throw new BusinessRuleException('Books are not enabled for this library.');
        }

        return DB::transaction(function () use ($item, $by) {
            $item = LoanItem::with(['student', 'book', 'copy'])->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($reason = self::blockReason($item)) {
                throw new BusinessRuleException($reason);
            }

            $rule = CirculationRules::rule($item->student->member_type);
            $from = $item->due_on->gte(today()) ? $item->due_on : today();      // extend from the due date, never earlier than today
            $due = CirculationRules::dueDate($from, $rule['renewal_days']);
            $old = $item->due_on->toDateString();

            $item->forceFill(['due_on' => $due->toDateString(), 'renewals' => $item->renewals + 1, 'last_renewed_at' => now()])->save();
            BookCopyEvent::log($item->copy, 'renewed', null, null, "Due {$old} → {$due->toDateString()} ({$by})");
            AuditLog::record('circulation.renewed', $item, ['book' => $item->book->title, 'student' => $item->student->name, 'from' => $old, 'to' => $due->toDateString(), 'by' => $by]);

            return $item;
        });
    }
}
