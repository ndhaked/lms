<?php

namespace App\Support;

use App\Models\Fine;
use App\Models\Library;
use App\Models\LoanItem;
use App\Models\Payment;
use App\Models\Student;
use App\Models\SubscriptionInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** The data behind each downloadable PDF, in the shape resources/views/pdf/document.blade.php draws. */
class PdfDocs
{
    protected static function rupees(mixed $v): string
    {
        return '₹'.number_format((float) $v, 2);
    }

    protected static function libraryHeader(Library $library): array
    {
        $s = LibrarySettings::all($library->id);

        return [
            'sender' => ['name' => $library->name, 'lines' => [$s['tax_number'] ?: null, $library->address, collect([$library->phone, $library->email])->filter()->implode(' · ')]],
            'headerNote' => $s['receipt_header'], 'taxNote' => $s['tax_note'], 'receiptFooter' => $s['receipt_footer'],
        ];
    }

    protected static function party(string $label, Student $student): array
    {
        return ['label' => $label, 'name' => $student->name, 'lines' => [$student->student_code.' · '.$student->phone]];
    }

    public static function payment(Payment $payment, Library $library): array
    {
        return self::libraryHeader($library) + [
            'kind' => 'Receipt', 'number' => $payment->receipt_no, 'date' => $payment->paid_on->format('d M Y'),
            'party' => self::party('Received from', $payment->student),
            'meta' => [['Payment method', Payment::METHODS[$payment->method] ?? $payment->method], ['Reference', $payment->reference]],
            'columns' => ['Towards', 'Amount'],
            'rows' => $payment->allocations->map(fn ($a) => [$a->membership->plan->name.' ('.$a->membership->starts_on->format('d M Y').' – '.$a->membership->ends_on->format('d M Y').')', self::rupees($a->amount)])->all(),
            'totalLabel' => 'Total received', 'total' => self::rupees($payment->amount),
            'notes' => $payment->refunds->map(fn ($r) => "Refund {$r->refund_no} · {$r->refunded_on->format('d M Y')} · ".self::rupees($r->amount).' — '.$r->reason)->all(),
            'reversed' => $payment->isReversed() ? 'This payment was reversed on '.$payment->reversed_at->format('d M Y').': '.$payment->reversal_reason : null,
            'footer' => 'Recorded by '.($payment->recorder?->name ?? '—').' · Computer-generated receipt',
        ];
    }

    /** @param Collection<int, LoanItem> $items  @param Collection<int, Fine> $fines */
    public static function circulation(string $kind, Library $library, Student $student, string $number, ?CarbonInterface $date, ?string $by, Collection $items, Collection $fines): array
    {
        $rows = [];
        foreach ($items as $i) {
            $rows[] = [$i->book->title.($i->book->author ? "\n".$i->book->author : ''), $i->copy->accession_no, $kind === 'issue' ? $i->due_on->format('d M Y') : ucfirst($i->return_condition ?? 'good')];
        }
        foreach ($fines as $f) {
            $rows[] = [(Fine::TYPES[$f->type] ?? $f->type).($f->note ? "\n".$f->note : ''), '', self::rupees($kind === 'fine' ? $f->paid_amount : $f->amount)];
        }
        $total = $fines->sum(fn ($f) => (float) ($kind === 'fine' ? $f->paid_amount : $f->amount));

        return self::libraryHeader($library) + [
            'kind' => ['issue' => 'Issue slip', 'return' => 'Return receipt', 'fine' => 'Fine receipt'][$kind], 'number' => $number, 'date' => $date?->format('d M Y, H:i'),
            'party' => self::party('Member', $student), 'meta' => [],
            'columns' => [$kind === 'fine' ? 'Fine' : 'Book', 'Accession', $kind === 'issue' ? 'Due date' : ($kind === 'fine' ? 'Paid' : 'Condition / fine')],
            'rows' => $rows,
            'totalLabel' => $kind === 'fine' ? 'Total paid' : 'Fines raised', 'total' => $fines->isNotEmpty() ? self::rupees($total) : null,
            'notes' => $kind === 'issue' ? ['Please return the books by their due dates to avoid fines.'] : ($kind === 'return' && $fines->isNotEmpty() ? ['Fines can be paid at the library desk.'] : []),
            'footer' => $by ? 'Handled by '.$by : '',
        ];
    }

    public static function invoice(SubscriptionInvoice $invoice, array $platform): array
    {
        $period = $invoice->period_starts_on->format('d M Y').' – '.$invoice->period_ends_on->format('d M Y');
        $lines = collect((array) $invoice->lines)->map(fn ($l) => [$l['label'], $period, self::rupees($l['amount'])])->all()
            ?: [[$invoice->description, $period, self::rupees($invoice->amount)]];

        return [
            'sender' => ['name' => $platform['app_name'] ?: config('app.name'), 'lines' => [collect([$platform['support_email'], $platform['support_phone']])->filter()->implode(' · ')]],
            'kind' => 'Subscription invoice', 'number' => $invoice->invoice_no, 'date' => $invoice->issued_on->format('d M Y'),
            'party' => ['label' => 'Billed to', 'name' => $invoice->library->name, 'lines' => [$invoice->library->code, $invoice->library->address]],
            'meta' => [['Status', ucfirst($invoice->status)], ['Payment ref', $invoice->payment?->reference]],
            'columns' => ['Description', 'Period', 'Amount'], 'rows' => $lines,
            'totalLabel' => 'Total', 'total' => self::rupees($invoice->amount), 'notes' => [],
            'footer' => "This is a computer-generated invoice for the library's platform subscription. It is separate from the fees your library collects from its students.",
        ];
    }
}
