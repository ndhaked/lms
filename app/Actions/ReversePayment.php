<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class ReversePayment
{
    /**
     * Voids a payment recorded in error. The record stays (status "reversed") with who/when/why,
     * and it stops counting toward what the student has paid. A payment that has refunds cannot
     * be reversed: its refunds must stand on their own.
     */
    public function __invoke(Payment $payment, string $reason): Payment
    {
        abort_unless(auth()->user()?->can('payments.refund'), 403);

        if (blank($reason)) {
            throw new BusinessRuleException('A reason is required to reverse a payment.');
        }

        return DB::transaction(function () use ($payment, $reason) {
            $payment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($payment->isReversed()) {
                throw new BusinessRuleException('This payment has already been reversed.');
            }
            if ($payment->refunds()->exists()) {
                throw new BusinessRuleException('This payment has refunds and cannot be reversed.');
            }

            $payment->forceFill([
                'status' => Payment::REVERSED,
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
                'reversal_reason' => $reason,
            ])->save();

            AuditLog::record('payment.reversed', $payment, ['receipt' => $payment->receipt_no, 'amount' => (string) $payment->amount, 'reason' => $reason]);

            return $payment;
        });
    }
}
