<?php

namespace App\Domain\Reports\Export;

use App\Models\Report;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** One worksheet per data section plus a provenance sheet. Values stay numeric with number formats. */
class XlsxExporter implements Exporter
{
    public function export(Report $report, string $path): void
    {
        $theme = Theme::get($report->theme);
        $book = new Spreadsheet;
        $book->getProperties()->setTitle($report->title)->setCreator('AIXBI');
        $summary = $book->getActiveSheet()->setTitle('Summary');
        $summary->setCellValue('A1', $report->title)->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $summary->setCellValue('A2', 'Generated '.now()->toDayDateTimeString().' · version '.$report->current_version);
        $row = 4;

        $used = ['Summary' => true];
        foreach ($report->sections as $section) {
            $c = $section->content;
            if ($section->type === 'summary') {
                foreach ($c['paragraphs'] ?? [] as $p) {
                    $summary->setCellValue("A{$row}", $p);
                    $row++;
                }

                continue;
            }
            $rows = $this->rows($section->type, $c);
            if (! $rows) {
                continue;
            }
            $name = substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $section->title), 0, 28);
            $i = 2;
            $base = $name;
            while (isset($used[$name])) {
                $name = substr($base, 0, 25).' '.$i++;
            }
            $used[$name] = true;
            $sheet = $book->createSheet()->setTitle($name);
            $sheet->fromArray($rows['header'], null, 'A1');
            $sheet->fromArray($rows['data'], null, 'A2', true);
            $last = chr(ord('A') + count($rows['header']) - 1);
            $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($theme['ink']);
            foreach ($rows['formats'] as $col => $fmt) {
                $sheet->getStyle("{$col}2:{$col}".(count($rows['data']) + 1))->getNumberFormat()->setFormatCode($fmt);
            }
            foreach (range('A', $last) as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        $prov = $book->createSheet()->setTitle('Provenance');
        $prov->fromArray([['Section', 'Evidence (query hash)']], null, 'A1');
        $r = 2;
        foreach ($report->sections as $s) {
            foreach ($s->content['evidence'] ?? [] as $e) {
                $prov->fromArray([[$s->title, $e['query_hash'] ?? json_encode($e)]], null, "A{$r}");
                $r++;
            }
        }

        (new Xlsx($book))->save($path);
    }

    /** @return array{header: array<int, array<string>>, data: array<int, array<mixed>>, formats: array<string, string>}|null */
    private function rows(string $type, array $c): ?array
    {
        $fmt = fn (?string $f) => match ($f) {
            'percent' => '0.0%', 'currency' => '"RM" #,##0', 'duration_days' => '0.0', default => '#,##0.##',
        };

        return match ($type) {
            'kpis' => ['header' => [['Metric', 'Value', 'Previous', 'Change', 'Change %', 'Target', 'Status']],
                'data' => array_map(fn ($k) => [$k['label'], $k['value'], $k['previous'], $k['change'], $k['change_pct'], $k['target'], $k['target_status'] ?? $k['sentiment']], $c['cards'] ?? []),
                'formats' => ['E' => '0.0%']],
            'chart' => ['header' => [['Period', $c['label'] ?? 'Value']], 'data' => array_map(fn ($p) => [$p['period'], $p['value']], $c['series'] ?? []), 'formats' => ['B' => $fmt($c['format'] ?? null)]],
            'breakdown' => ['header' => [[$c['dimension_label'] ?? 'Member', $c['label'] ?? 'Value']], 'data' => array_map(fn ($r) => [$r['member'], $r['value']], $c['rows'] ?? []), 'formats' => ['B' => $fmt($c['format'] ?? null)]],
            'forecast' => ['header' => [['Period', 'Forecast', 'Lower', 'Upper']], 'data' => array_map(fn ($p) => [$p['period'], $p['value'], $p['lower'], $p['upper']], $c['points'] ?? []),
                'formats' => ['B' => $fmt($c['format'] ?? null), 'C' => $fmt($c['format'] ?? null), 'D' => $fmt($c['format'] ?? null)]],
            'anomalies' => ['header' => [['Metric', 'Date', 'Expected', 'Actual', 'Score (σ)', 'Severity']], 'data' => array_map(fn ($a) => [$a['label'], $a['period'], $a['expected'], $a['actual'], $a['score'], $a['severity']], $c['items'] ?? []), 'formats' => []],
            'root_cause' => ['header' => [['Dimension', 'Member', 'Current', 'Previous', 'Impact', 'Share of change']],
                'data' => array_map(fn ($d) => [$d['dimension_label'], $d['member'], $d['current_value'], $d['previous_value'], $d['impact'], $d['impact_share']], $c['drivers'] ?? []), 'formats' => ['F' => '0%']],
            'risks' => ['header' => [['Severity', 'Risk', 'Detail']], 'data' => array_map(fn ($r) => [$r['severity'], $r['title'], $r['detail']], $c['items'] ?? []), 'formats' => []],
            default => null,
        };
    }

    public function extension(): string
    {
        return 'xlsx';
    }

    public function mime(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
}
