<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Support\Tenancy;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    /** A student's own receipt. Only payments of the student linked to this login are available. */
    public function mine(Request $request, string $library, string $payment)
    {
        abort_unless($request->user()->can('self.view'), 403);

        $payment = Payment::with(['student', 'recorder', 'allocations.membership.plan', 'refunds.membership.plan'])->findOrFail($payment);
        abort_unless($payment->student->user_id === $request->user()->id, 404);

        return $this->respond($request, $payment);
    }

    public function show(Request $request, string $library, string $payment)
    {
        abort_unless($request->user()->can('receipts.print'), 403);

        // Tenant-scoped lookup: a receipt id from another library is a 404.
        $payment = Payment::with(['student', 'recorder', 'allocations.membership.plan', 'refunds.membership.plan'])->findOrFail($payment);

        return $this->respond($request, $payment);
    }

    /** The printable page, or the same receipt as a PDF file with ?format=pdf. */
    protected function respond(Request $request, Payment $payment)
    {
        if ($request->query('format') === 'pdf') {
            return \App\Support\Pdf::download('pdf.document', \App\Support\PdfDocs::payment($payment, Tenancy::library()), "receipt-{$payment->receipt_no}.pdf");
        }

        return view('receipts.payment', ['payment' => $payment, 'library' => Tenancy::library()]);
    }
}
