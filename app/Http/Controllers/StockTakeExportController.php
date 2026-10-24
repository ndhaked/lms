<?php

namespace App\Http\Controllers;

use App\Actions\Books\StockTake;
use App\Models\AuditLog;
use App\Models\InventoryAudit;
use App\Models\BookCopy;
use Illuminate\Http\Request;

/** The discrepancies of one stock-take as a CSV: missing copies, unexpected copies, and codes that matched nothing. */
class StockTakeExportController extends Controller
{
    public function __invoke(Request $request, string $library, string $audit)
    {
        abort_unless($request->user()->can('books.audit'), 403);
        $audit = InventoryAudit::findOrFail($audit);   // tenant-scoped

        AuditLog::record('stocktake.exported', $audit, ['name' => $audit->name]);

        $safe = fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;

        return response()->streamDownload(function () use ($audit, $safe) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Result', 'Accession no.', 'Title', 'Author', 'Shelf', 'Register said']);

            foreach (StockTake::missing($audit)->orderBy('id')->lazy(500) as $i) {
                fputcsv($out, ['Missing', $i->copy->accession_no, $safe($i->copy->book->title), $safe($i->copy->book->author), $i->copy->book->shelf, BookCopy::STATUSES[$i->status_at_start] ?? $i->status_at_start]);
            }
            foreach (StockTake::unexpected($audit)->orderBy('id')->lazy(500) as $i) {
                fputcsv($out, ['Found but not expected', $i->copy->accession_no, $safe($i->copy->book->title), $safe($i->copy->book->author), $i->copy->book->shelf, BookCopy::STATUSES[$i->status_at_start] ?? $i->status_at_start]);
            }
            foreach ((array) $audit->unknown_codes as $code) {
                fputcsv($out, ['Unknown code', $safe($code), '', '', '', '']);
            }
            fclose($out);
        }, 'stock-take-'.preg_replace('/[^A-Za-z0-9]+/', '-', $audit->name).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
