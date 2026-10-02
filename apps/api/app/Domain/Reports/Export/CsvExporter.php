<?php

namespace App\Domain\Reports\Export;

use App\Models\Report;

/** Flat, long-format CSV of every number in the report: section, item, measure, value. */
class CsvExporter implements Exporter
{
    public function export(Report $report, string $path): void
    {
        $fh = fopen($path, 'w');
        fputcsv($fh, ['section', 'item', 'measure', 'value']);
        foreach ($report->sections as $s) {
            $c = $s->content;
            foreach ($c['cards'] ?? [] as $k) {
                fputcsv($fh, [$s->title, $k['label'], 'value', $k['value']]);
                fputcsv($fh, [$s->title, $k['label'], 'previous', $k['previous']]);
            }
            foreach ($c['series'] ?? [] as $p) {
                fputcsv($fh, [$s->title, $p['period'], $c['label'] ?? 'value', $p['value']]);
            }
            foreach ($c['rows'] ?? [] as $r) {
                fputcsv($fh, [$s->title, $r['member'], $c['label'] ?? 'value', $r['value']]);
            }
            foreach ($c['points'] ?? [] as $p) {
                fputcsv($fh, [$s->title, $p['period'], 'forecast', $p['value']]);
            }
        }
        fclose($fh);
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function mime(): string
    {
        return 'text/csv';
    }
}
