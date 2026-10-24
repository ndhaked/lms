<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\StudentMembership;
use App\Support\NextNumber;
use Illuminate\Support\Facades\DB;

class RecordRefund
{
    /** Refunds part (or all) of what a payment contributed to one membership. Originals are never edited. */
    public function __invoke(Payment $payment, StudentMembership $membership, string|float $amount, string $method, string $reason): Refund
    {
        abort_unless(auth()->user()?->can('payments.refund'), 403);

        $amount = number_format((float) $amount, 2, '.', '');

        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new BusinessRuleException('Enter a refund amount greater than zero.');
        }
        if (blank($reason)) {
            throw new BusinessRuleException('A reason is required for a refund.');
        }
        if (! array_key_exists($method, Payment::METHODS)) {
            throw new BusinessRuleException('Choose a valid refund method.');
        }

        return DB::transaction(function () use ($payment, $membership, $amount, $method, $reason) {
            $payment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($payment->isReversed()) {
                throw new BusinessRuleException('A reversed payment cannot be refunded.');
            }

            $allocated = (string) PaymentAllocation::where('payment_id', $payment->id)
                ->where('student_membership_id', $membership->id)->sum('amount');
            $already = (string) Refund::where('payment_id', $payment->id)->where('student_membership_id', $membership->id)->sum('amount');
            $refundable = bcsub(number_format((float) $allocated, 2, '.', ''), number_format((float) $already, 2, '.', ''), 2);

            if (bccomp($refundable, '0', 2) <= 0) {
                throw new BusinessRuleException('Nothing left to refund on this payment for that membership.');
            }
            if (bccomp($amount, $refundable, 2) > 0) {
                throw new BusinessRuleException("You can refund at most ₹{$refundable} here.");
            }

            $refund = Refund::create([
                'payment_id' => $payment->id,
                'student_membership_id' => $membership->id,
                'refund_no' => NextNumber::for('refund', 'RF'),
                'amount' => $amount,
                'method' => $method,
                'reason' => $reason,
                'refunded_on' => today(),
                'recorded_by' => auth()->id(),
            ]);

            AuditLog::record('payment.refunded', $refund, [
                'refund' => $refund->refund_no, 'receipt' => $payment->receipt_no, 'amount' => $amount, 'reason' => $reason,
            ]);

            return $refund;
        });
    }
}
