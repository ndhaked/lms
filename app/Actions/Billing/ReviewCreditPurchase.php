<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\AuthorizesPlatform;
use App\Exceptions\BusinessRuleException;
use App\Models\CreditPurchase;
use App\Models\Library;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;

class ReviewCreditPurchase
{
    use AuthorizesPlatform;

    /** Approval by the system after a verified online payment (see FulfilOnlinePayment). */
    public function system(CreditPurchase $purchase, ?string $note = null): CreditPurchase
    {
        return $this->approve($purchase, $note, trusted: true);
    }

    public function approve(CreditPurchase $purchase, ?string $note = null, bool $trusted = false): CreditPurchase
    {
        if (! $trusted) {
            $this->authorizePlatform('payments.approve');
        }

        return DB::transaction(function () use ($purchase, $note) {
            $purchase = CreditPurchase::lockForUpdate()->findOrFail($purchase->id);
            $this->assertPending($purchase);

            $column = $purchase->channel.'_credits';
            Library::whereKey($purchase->library_id)->increment($column, $purchase->credits);
            $this->close($purchase, 'approved', $note);

            Notifier::library($purchase->library, 'billing.view', 'credits.approved', number_format($purchase->credits).' '.CreditPurchase::CHANNELS[$purchase->channel].' credits added', 'Your payment was approved.',
                route('app.subscription.plans', ['library' => $purchase->library->slug, 'tab' => $purchase->channel]));

            return $purchase;
        });
    }

    public function reject(CreditPurchase $purchase, string $reason): CreditPurchase
    {
        $this->authorizePlatform('payments.approve');

        if (blank($reason)) {
            throw new BusinessRuleException('A reason is required to reject a purchase.');
        }

        return DB::transaction(function () use ($purchase, $reason) {
            $purchase = CreditPurchase::lockForUpdate()->findOrFail($purchase->id);
            $this->assertPending($purchase);
            $this->close($purchase, 'rejected', $reason);

            Notifier::library($purchase->library, 'billing.view', 'credits.rejected', CreditPurchase::CHANNELS[$purchase->channel].' credit payment rejected', $reason,
                route('app.subscription.plans', ['library' => $purchase->library->slug, 'tab' => $purchase->channel]));

            return $purchase;
        });
    }

    protected function assertPending(CreditPurchase $purchase): void
    {
        if ($purchase->status !== 'pending') {
            throw new BusinessRuleException('This purchase has already been reviewed.');
        }
    }

    protected function close(CreditPurchase $purchase, string $status, ?string $note): void
    {
        $purchase->update(['status' => $status, 'review_note' => $note, 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
    }
}
