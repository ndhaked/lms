<?php

namespace App\Http\Controllers;

use App\Models\CreditPurchaseProof;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Proofs for credit purchases are private files: only platform staff who approve payments, and the owning library, may open them. */
class CreditProofController extends Controller
{
    public function platform(Request $request, string $proof)
    {
        abort_unless($request->user()->hasPlatformPermission('payments.approve'), 403);

        return $this->send(CreditPurchaseProof::findOrFail($proof));
    }

    public function library(Request $request, string $library, string $proof)
    {
        abort_unless($request->user()->can('billing.view'), 403);

        $proof = CreditPurchaseProof::with('purchase')->findOrFail($proof);
        abort_unless($proof->purchase->library_id === Tenancy::currentId(), 404);   // another library's proof is a 404

        return $this->send($proof);
    }

    protected function send(CreditPurchaseProof $proof)
    {
        abort_unless(Storage::disk('local')->exists($proof->path), 404);

        return Storage::disk('local')->response($proof->path, $proof->original_name, [
            'Content-Type' => $proof->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
        ]);
    }
}
