<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Support\LibrarySettings;
use App\Support\NextNumber;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class RecordPayment
{
    /**
     * Records money received from a student and applies it to what they owe.
     *  - With $membership: the payment goes to that membership only (partial payments allowed).
     *  - Without: it clears the oldest outstanding memberships first.
     * Overpayment is refused (advance payments are not supported). A repeated $idempotencyKey
     * returns the original payment instead of recording a second one.
     */
    public function __invoke(
        Student $student,
        string|float $amount,
        string $method,
        CarbonInterface $paidOn,
        ?StudentMembership $membership = null,
        ?string $reference = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): Payment {
        abort_unless(auth()->user()?->can('payments.record'), 403);

        $amount = number_format((float) $amount, 2, '.', '');

        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new BusinessRuleException('Enter an amount greater than zero.');
        }
        if (bccomp($amount, '9999999.99', 2) > 0) {
            throw new BusinessRuleException('That amount is too large.');
        }
        if (! array_key_exists($method, Payment::METHODS)) {
            throw new BusinessRuleException('Choose a valid payment method.');
        }
        if ($paidOn->isFuture()) {
            throw new BusinessRuleException('The payment date cannot be in the future.');
        }

        $created = false;

        try {
            $payment = DB::transaction(function () use ($student, $amount, $method, $paidOn, $membership, $reference, $notes, $idempotencyKey, &$created) {
                // Serialise payments per student so concurrent submissions cannot over-allocate.
                Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

                if ($idempotencyKey && ($existing = Payment::where('idempotency_key', $idempotencyKey)->first())) {
                    return $existing;
                }

                if ($membership) {
                    if ($membership->student_id !== $student->id) {
                        throw new BusinessRuleException('That membership belongs to a different student.');
                    }
                    $targets = collect([StudentMembership::findOrFail($membership->id)]);
                } else {
                    $targets = StudentMembership::query()->where('student_id', $student->id)->where('status', '!=', 'cancelled')
                        ->orderBy('ends_on')->orderBy('id')->get();
                }

                $remaining = $amount;
                $allocations = [];
                foreach ($targets as $m) {
                    if (bccomp($remaining, '0', 2) <= 0) {
                        break;
                    }
                    $owed = $m->outstanding();
                    if (bccomp($owed, '0', 2) <= 0) {
                        continue;
                    }
                    $part = bccomp($remaining, $owed, 2) <= 0 ? $remaining : $owed;
                    $allocations[] = [$m->id, $part];
                    $remaining = bcsub($remaining, $part, 2);
                }

                if (! $allocations) {
                    throw new BusinessRuleException('Nothing is outstanding for this student.');
                }
                if (bccomp($remaining, '0', 2) > 0) {
                    $owedTotal = bcsub($amount, $remaining, 2);
                    throw new BusinessRuleException("The amount exceeds what is outstanding (₹{$owedTotal}). Advance payments are not supported.");
                }

                $payment = Payment::create([
                    'student_id' => $student->id,
                    'receipt_no' => NextNumber::for('receipt', LibrarySettings::get('receipt_prefix') ?: 'RC'),
                    'amount' => $amount,
                    'method' => $method,
                    'reference' => $reference ?: null,
                    'paid_on' => $paidOn->toDateString(),
                    'notes' => $notes ?: null,
                    'idempotency_key' => $idempotencyKey,
                    'recorded_by' => auth()->id(),
                ]);

                foreach ($allocations as [$membershipId, $part]) {
                    PaymentAllocation::create(['payment_id' => $payment->id, 'student_membership_id' => $membershipId, 'amount' => $part]);
                }

                AuditLog::record('payment.recorded', $payment, [
                    'receipt' => $payment->receipt_no, 'amount' => $amount, 'method' => $method, 'student' => $student->student_code,
                ]);

                $created = true;

                return $payment;
            });

            if ($created) {
                \App\Support\Messaging::trigger('payment', $student, [
                    'amount' => '₹'.number_format((float) $amount, 2),
                    'due' => '₹'.number_format((float) StudentMembership::owing()->withBalance()->where('student_memberships.student_id', $student->id)->get()->sum('balance'), 2),
                ], "payment:{$payment->id}");
            }

            return $payment;
        } catch (UniqueConstraintViolationException) {
            // Same idempotency key submitted twice at the same instant: hand back the first one.
            return Payment::where('idempotency_key', $idempotencyKey)->firstOr(fn () => throw new BusinessRuleException('Could not record the payment. Please try again.'));
        }
    }
}
