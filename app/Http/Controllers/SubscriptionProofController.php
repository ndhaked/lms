<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPaymentProof;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Payment proofs are private files. Only platform staff who review payments, and the owning library, may open them. */
class SubscriptionProofController extends Controller
{
    public function platform(Request $request, string $proof)
    {
        abort_unless($request->user()->hasPlatformPermission('payments.approve') || $request->user()->hasPlatformPermission('subscriptions.manage'), 403);

        return $this->send(SubscriptionPaymentProof::with('payment')->findOrFail($proof));
    }

    public function library(Request $request, string $library, string $proof)
    {
        abort_unless($request->user()->can('billing.view'), 403);

        $proof = SubscriptionPaymentProof::with('payment')->findOrFail($proof);
        abort_unless($proof->payment->library_id === Tenancy::currentId(), 404);   // another library's proof is a 404

        return $this->send($proof);
    }

    protected function send(SubscriptionPaymentProof $proof)
    {
        abort_unless(Storage::disk('local')->exists($proof->path), 404);

        return Storage::disk('local')->response($proof->path, $proof->original_name, [
            'Content-Type' => $proof->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
        ]);
    }
}
