<?php

namespace App\Domain\Reports\Export;

use App\Models\Report;

/** Standalone interactive HTML: same layout as print, with live ECharts. */
class HtmlExporter implements Exporter
{
    public function export(Report $report, string $path): void
    {
        file_put_contents($path, view('reports.print', [
            'report' => $report->load('sections', 'organisation'), 'theme' => Theme::get($report->theme), 'interactive' => true,
        ])->render());
    }

    public function extension(): string
    {
        return 'html';
    }

    public function mime(): string
    {
        return 'text/html';
    }
}
