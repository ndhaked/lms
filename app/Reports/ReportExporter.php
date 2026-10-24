<?php

namespace App\Reports;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExporter
{
    /** @return iterable<list<scalar|null>> every row, streamed in chunks so large reports stay light */
    public static function rows(Report $report, array $filters): iterable
    {
        if ($query = $report->query($filters)) {
            foreach ($query->lazy(500) as $model) {
                yield $report->row($model);
            }

            return;
        }

        yield from $report->collection($filters);
    }

    /** A spreadsheet treats cells starting with = + - @ as formulas; neutralise them (CSV injection). */
    protected static function safe(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    public static function csv(Report $report, array $filters, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report, $filters) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₹ and accents correctly
            fputcsv($out, $report->headers());
            foreach (static::rows($report, $filters) as $row) {
                fputcsv($out, array_map([static::class, 'safe'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public const PDF_MAX_ROWS = 2000;

    /** A landscape PDF table. Long lists are better as Excel/CSV, so the PDF stops at a sensible size. */
    public static function pdf(Report $report, array $filters, string $filename): \Illuminate\Http\Response
    {
        $rows = [];
        foreach (static::rows($report, $filters) as $row) {
            if (count($rows) >= self::PDF_MAX_ROWS) {
                abort(422, 'This report has more than '.self::PDF_MAX_ROWS.' rows. Download it as Excel or CSV instead, or narrow the dates.');
            }
            $rows[] = array_map(fn ($v) => $v === null ? '' : (string) $v, $row);
        }

        $range = ($filters['from'] ?? '') !== '' && ($filters['from'] ?? '') !== '1970-01-01' ? ($filters['from'].' to '.($filters['until'] ?? '')) : '';

        return \App\Support\Pdf::download('pdf.report', [
            'title' => $report->title(), 'library' => \App\Support\Tenancy::library()?->name, 'generated' => now()->format('d M Y H:i'), 'range' => $range,
            'summary' => $report->summary($filters), 'headers' => $report->headers(), 'rows' => $rows,
        ], $filename, 'landscape');
    }

    public static function xlsx(Report $report, array $filters, string $filename): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt');

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($report->headers()));
        foreach (static::rows($report, $filters) as $row) {
            $writer->addRow(Row::fromValues(array_map(fn ($v) => $v === null ? '' : static::safe($v), $row)));
        }
        $writer->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
