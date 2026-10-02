<?php

namespace App\Domain\Reports\Export;

use App\Models\Report;
use Dompdf\Dompdf;
use Dompdf\Options;

class PdfExporter implements Exporter
{
    public function export(Report $report, string $path): void
    {
        $theme = Theme::get($report->theme);
        $html = view('reports.print', ['report' => $report->load('sections', 'organisation'), 'theme' => $theme])->render();
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        file_put_contents($path, $pdf->output());
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function mime(): string
    {
        return 'application/pdf';
    }
}
