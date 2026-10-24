<?php

namespace App\Actions\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\DB;

class RespondToPaymentInfoRequest
{
    /** The library answers a "more information needed" request; the payment goes back to the review queue. */
    public function __invoke(SubscriptionPayment $payment, string $answer, array $files = []): SubscriptionPayment
    {
        abort_unless(auth()->user()?->can('billing.manage'), 403);

        if (blank($answer) && count($files) === 0) {
            throw new BusinessRuleException('Write a reply or attach more proof.');
        }

        return DB::transaction(function () use ($payment, $answer, $files) {
            $payment = SubscriptionPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== SubscriptionPayment::INFO_REQUESTED) {
                throw new BusinessRuleException('This payment is not waiting for more information.');
            }

            foreach ($files as $file) {
                $payment->proofs()->create([
                    'path' => $file->store("subscription-proofs/{$payment->library_id}", 'local'),
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                    'mime' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(),
                ]);
            }

            $payment->forceFill([
                'status' => SubscriptionPayment::PENDING,
                'note' => trim(($payment->note ? $payment->note."\n\n" : '').'Reply: '.$answer),
            ])->save();

            SubscriptionEvent::log($payment->subscription, 'payment_info_provided', SubscriptionPayment::INFO_REQUESTED, SubscriptionPayment::PENDING);
            AuditLog::record('subscription.payment_info_provided', $payment, [], $payment->library_id);

            return $payment;
        });
    }
}
