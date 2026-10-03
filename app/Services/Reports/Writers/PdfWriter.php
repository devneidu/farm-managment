<?php

namespace App\Services\Reports\Writers;

use App\Services\Reports\ReportPayload;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/** A landscape A4 table rendering of a report payload (Dompdf, no remote resources, no PHP in templates). */
class PdfWriter
{
    public const MIME = 'application/pdf';

    public const EXTENSION = 'pdf';

    public function write(array $payload, string $path): void
    {
        $html = View::make('reports.pdf', ['p' => $payload, 'summary' => ReportPayload::flatSummary((array) $payload['summary']), 'right' => ['integer', 'decimal', 'money', 'percent']])->render();
        $tmp = storage_path('app/dompdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('tempDir', $tmp);
        $options->set('fontCache', $tmp);
        $options->set('chroot', [resource_path(), $tmp]);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        file_put_contents($path, $pdf->output());
    }
}
