<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\PaymentSubmission;
use App\Support\Notifier;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/** Staff decision on a student's payment claim. Each submission can be decided exactly once. */
class ReviewPaymentSubmission
{
    public function approve(PaymentSubmission $submission): PaymentSubmission
    {
        abort_unless(auth()->user()?->can('payments.record'), 403);

        $submission = DB::transaction(function () use ($submission) {
            $s = PaymentSubmission::with(['student', 'membership'])->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if ($s->status !== PaymentSubmission::PENDING) {
                throw new BusinessRuleException("This submission was already {$s->status}.");
            }

            // The real payment goes through the normal rules (no overpayment, FIFO, receipt number, audit).
            $payment = app(RecordPayment::class)(
                $s->student, (string) $s->amount, $s->method, $s->paid_on, $s->membership,
                $s->reference, 'Submitted by student'.($s->note ? ': '.$s->note : ''), "submission-{$s->id}",
            );

            $s->forceFill(['status' => PaymentSubmission::APPROVED, 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'payment_id' => $payment->id])->save();
            AuditLog::record('payment_submission.approved', $s, ['amount' => (string) $s->amount, 'payment' => $payment->receipt_no]);

            return $s;
        });

        $this->tellStudent($submission, 'Payment confirmed', 'Your payment of ₹'.number_format((float) $submission->amount, 2).' was confirmed. Receipt '.$submission->payment->receipt_no.'.');

        return $submission;
    }

    public function reject(PaymentSubmission $submission, string $reason): PaymentSubmission
    {
        abort_unless(auth()->user()?->can('payments.record'), 403);

        if (blank($reason)) {
            throw new BusinessRuleException('Give the student a reason for rejecting.');
        }

        $submission = DB::transaction(function () use ($submission, $reason) {
            $s = PaymentSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if ($s->status !== PaymentSubmission::PENDING) {
                throw new BusinessRuleException("This submission was already {$s->status}.");
            }

            $s->forceFill(['status' => PaymentSubmission::REJECTED, 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_note' => mb_substr(trim($reason), 0, 500)])->save();
            AuditLog::record('payment_submission.rejected', $s, ['amount' => (string) $s->amount, 'reason' => $s->review_note]);

            return $s;
        });

        $this->tellStudent($submission, 'Payment not confirmed', 'Your payment details were not accepted: '.$submission->review_note);

        return $submission;
    }

    protected function tellStudent(PaymentSubmission $s, string $title, string $body): void
    {
        $user = $s->student?->user;
        if ($user) {
            Notifier::user($user, Tenancy::currentId(), 'payment_submission.'.$s->status, $title, $body, route('app.my.index', ['library' => Tenancy::slug()]));
        }
    }
}
