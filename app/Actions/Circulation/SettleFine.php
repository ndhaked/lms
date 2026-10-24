<?php

namespace App\Actions\Circulation;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Fine;
use App\Models\Payment;
use App\Models\Student;
use App\Support\NextNumber;
use Illuminate\Support\Facades\DB;

/** Collecting or waiving a fine. Money collected here is a fine receipt (FN-…), separate from membership payments. */
class SettleFine
{
    public function collect(Fine $fine, string $method, ?string $reference = null): Fine
    {
        abort_unless(auth()->user()?->can('fines.collect'), 403);

        if (! array_key_exists($method, Payment::METHODS)) {
            throw new BusinessRuleException('Choose a payment method.');
        }
        $reference = filled($reference) ? mb_substr(trim($reference), 0, 100) : null;

        return DB::transaction(function () use ($fine, $method, $reference) {
            $fine = Fine::whereKey($fine->id)->lockForUpdate()->firstOrFail();

            if ($fine->status !== Fine::PENDING) {
                throw new BusinessRuleException('This fine is already settled.');
            }
            $due = $fine->outstanding();
            if (bccomp($due, '0.00', 2) <= 0) {
                throw new BusinessRuleException('Nothing is left to collect on this fine.');
            }

            $fine->forceFill([
                'paid_amount' => bcadd((string) $fine->paid_amount, $due, 2), 'status' => Fine::PAID, 'method' => $method, 'reference' => $reference,
                'receipt_no' => NextNumber::for('fine', 'FN'), 'paid_on' => today(), 'settled_by' => auth()->id(), 'settled_at' => now(),
            ])->save();

            AuditLog::record('fine.collected', $fine, ['amount' => $due, 'receipt' => $fine->receipt_no, 'method' => $method, 'student' => Student::find($fine->student_id)?->name]);

            return $fine;
        });
    }

    /** Waive all of what is left, or just part of it (the rest can still be collected). */
    public function waive(Fine $fine, string $reason, string|float|null $amount = null): Fine
    {
        abort_unless(auth()->user()?->can('fines.waive'), 403);

        if (blank($reason)) {
            throw new BusinessRuleException('A reason is required to waive a fine.');
        }

        return DB::transaction(function () use ($fine, $reason, $amount) {
            $fine = Fine::whereKey($fine->id)->lockForUpdate()->firstOrFail();

            if ($fine->status !== Fine::PENDING) {
                throw new BusinessRuleException('This fine is already settled.');
            }

            $left = $fine->outstanding();
            $waive = filled($amount) ? number_format((float) $amount, 2, '.', '') : $left;
            if (! is_numeric($waive) || bccomp($waive, '0.00', 2) <= 0 || bccomp($waive, $left, 2) > 0) {
                throw new BusinessRuleException('The amount to waive must be more than zero and at most ₹'.$left.'.');
            }

            $fine->waived_amount = bcadd((string) $fine->waived_amount, $waive, 2);
            $fine->waive_reason = mb_substr(trim($reason), 0, 500);
            if (bccomp($fine->outstanding(), '0.00', 2) === 0) {
                $fine->status = Fine::WAIVED;
                $fine->settled_by = auth()->id();
                $fine->settled_at = now();
            }
            $fine->save();

            AuditLog::record('fine.waived', $fine, ['amount' => $waive, 'left' => $fine->outstanding(), 'reason' => $fine->waive_reason, 'student' => Student::find($fine->student_id)?->name]);

            return $fine;
        });
    }
}
