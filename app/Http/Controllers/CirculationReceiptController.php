<?php

namespace App\Http\Controllers;

use App\Models\Fine;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Student;
use App\Support\Entitlements;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/** Printable issue, return and fine receipts. Staff with the right permission, or the member the receipt is about. */
class CirculationReceiptController extends Controller
{
    protected function allowed(Request $request, int $studentId, string $permission): void
    {
        abort_unless(Entitlements::hasModule('books'), 403);

        $user = $request->user();
        $mine = $user->can('self.view') && Student::where('user_id', $user->id)->whereKey($studentId)->exists();
        abort_unless($user->can($permission) || $mine, 403);
    }

    protected function respond(Request $request, array $d)
    {
        if ($request->query('format') === 'pdf') {
            return \App\Support\Pdf::download('pdf.document', \App\Support\PdfDocs::circulation($d['kind'], $d['library'], $d['student'], $d['number'], $d['date'], $d['by'], $d['items'], $d['fines']), "{$d['kind']}-{$d['number']}.pdf");
        }

        return view('receipts.circulation', $d);
    }

    public function issue(Request $request, string $library, string $loan)
    {
        $loan = Loan::with(['student', 'items.book', 'items.copy', 'issuer'])->findOrFail($loan);   // tenant-scoped
        $this->allowed($request, $loan->student_id, 'circulation.view');

        return $this->respond($request, ['kind' => 'issue', 'library' => Tenancy::library(), 'student' => $loan->student, 'number' => $loan->loan_no,
            'date' => $loan->issued_at, 'by' => $loan->issuer?->name, 'items' => $loan->items->where('status', '!=', LoanItem::REVERSED), 'fines' => collect()]);
    }

    public function return(Request $request, string $library, string $receipt)
    {
        $items = LoanItem::with(['student', 'book', 'copy', 'fines', 'returner'])->where('return_receipt_no', $receipt)->get();
        abort_if($items->isEmpty(), 404);
        $this->allowed($request, $items->first()->student_id, 'circulation.view');

        return $this->respond($request, ['kind' => 'return', 'library' => Tenancy::library(), 'student' => $items->first()->student, 'number' => $receipt,
            'date' => $items->first()->returned_at, 'by' => $items->first()->returner?->name, 'items' => $items, 'fines' => $items->flatMap->fines]);
    }

    public function fine(Request $request, string $library, string $fine)
    {
        $fine = Fine::with(['student', 'loanItem.book'])->findOrFail($fine);
        abort_unless($fine->status === Fine::PAID, 404);
        $this->allowed($request, $fine->student_id, 'fines.view');

        return $this->respond($request, ['kind' => 'fine', 'library' => Tenancy::library(), 'student' => $fine->student, 'number' => $fine->receipt_no,
            'date' => $fine->settled_at, 'by' => $fine->settler?->name, 'items' => collect(), 'fines' => collect([$fine])]);
    }
}
