<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

/** Server-side PDF files (receipts, invoices, catalogue). Plain HTML/CSS in, a download out; nothing is stored. */
class Pdf
{
    public static function make(string $view, array $data, string $orientation = 'portrait'): string
    {
        $cache = storage_path('app/dompdf');
        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');          // has the rupee sign and most scripts
        $options->set('isRemoteEnabled', false);              // never fetch anything from the internet while rendering
        $options->set('isHtml5ParserEnabled', true);
        $options->set('tempDir', $cache);
        $options->set('fontCache', $cache);
        $options->set('chroot', [base_path()]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, $data)->render());
        $dompdf->setPaper('a4', $orientation);
        $dompdf->render();

        return $dompdf->output();
    }

    public static function download(string $view, array $data, string $filename, string $orientation = 'portrait'): Response
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);

        return response(self::make($view, $data, $orientation), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
