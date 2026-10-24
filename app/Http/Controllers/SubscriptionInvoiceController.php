<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionInvoice;
use App\Support\BillingSettings;
use App\Support\Tenancy;
use Illuminate\Http\Request;

class SubscriptionInvoiceController extends Controller
{
    public function platform(Request $request, string $invoice)
    {
        abort_unless($request->user()->hasPlatformPermission('subscriptions.manage') || $request->user()->hasPlatformPermission('payments.approve'), 403);

        return $this->render($request, SubscriptionInvoice::with(['library', 'payment', 'subscription'])->findOrFail($invoice));
    }

    public function library(Request $request, string $library, string $invoice)
    {
        abort_unless($request->user()->can('billing.view'), 403);

        $invoice = SubscriptionInvoice::with(['library', 'payment', 'subscription'])->findOrFail($invoice);
        abort_unless($invoice->library_id === Tenancy::currentId(), 404);   // another library's invoice is a 404

        return $this->render($request, $invoice);
    }

    protected function render(Request $request, SubscriptionInvoice $invoice)
    {
        if ($request->query('format') === 'pdf') {
            return \App\Support\Pdf::download('pdf.document', \App\Support\PdfDocs::invoice($invoice, BillingSettings::all()), "invoice-{$invoice->invoice_no}.pdf");
        }

        return view('receipts.subscription-invoice', ['invoice' => $invoice, 'platform' => BillingSettings::all()]);
    }
}
