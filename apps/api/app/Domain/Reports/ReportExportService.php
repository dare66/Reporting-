<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Export\CsvExporter;
use App\Domain\Reports\Export\DocxExporter;
use App\Domain\Reports\Export\Exporter;
use App\Domain\Reports\Export\HtmlExporter;
use App\Domain\Reports\Export\PdfExporter;
use App\Domain\Reports\Export\PptxExporter;
use App\Domain\Reports\Export\XlsxExporter;
use App\Models\Report;
use App\Models\ReportExport;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class ReportExportService
{
    public const FORMATS = ['pdf', 'pptx', 'docx', 'xlsx', 'csv', 'html'];

    public function exporter(string $format): Exporter
    {
        return match ($format) {
            'pdf' => new PdfExporter,
            'pptx' => new PptxExporter,
            'docx' => new DocxExporter,
            'xlsx' => new XlsxExporter,
            'csv' => new CsvExporter,
            'html' => new HtmlExporter,
            default => throw new InvalidArgumentException("Unsupported export format {$format}."),
        };
    }

    public function run(ReportExport $export): ReportExport
    {
        $export->update(['status' => 'running']);
        try {
            $report = Report::withoutGlobalScopes()->with('sections', 'organisation')->findOrFail($export->report_id);
            $exporter = $this->exporter($export->format);
            $relative = "exports/{$export->organisation_id}/{$export->id}.{$exporter->extension()}";
            Storage::disk('local')->makeDirectory(dirname($relative));
            $exporter->export($report, Storage::disk('local')->path($relative));
            $export->update(['status' => 'ready', 'path' => $relative, 'bytes' => Storage::disk('local')->size($relative)]);
        } catch (Throwable $e) {
            report($e);
            $export->update(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 1000)]);
        }

        return $export;
    }
}
