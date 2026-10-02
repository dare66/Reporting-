<?php

namespace App\Domain\Reports\Export;

use App\Domain\Analytics\Format;
use App\Models\Report;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\Chart\Series;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Area;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Bar;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Line;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Slide\Background\Color as BackgroundColor;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;

/**
 * 16:9 executive deck with native (editable) PowerPoint charts.
 * Layout grid: 960×540 px canvas, 48 px margins, accent rule on every slide.
 */
class PptxExporter implements Exporter
{
    private array $theme;

    private PhpPresentation $deck;

    private int $slideNo = 0;

    public function export(Report $report, string $path): void
    {
        $this->theme = Theme::get($report->theme);
        $this->deck = new PhpPresentation;
        $this->deck->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);
        $this->deck->getDocumentProperties()->setTitle($report->title)->setCreator('AIXBI');
        $this->deck->removeSlideByIndex(0);

        $this->cover($report);
        foreach ($report->sections as $section) {
            $c = $section->content;
            if (isset($c['error'])) {
                continue;
            }
            match ($section->type) {
                'summary' => $this->bulletSlide($section->title, $c['paragraphs'] ?? []),
                'kpis' => $this->kpiSlide($section->title, $c['cards'] ?? []),
                'chart' => $this->chartSlide($section->title, $c, 'trend'),
                'breakdown' => $this->chartSlide($section->title, $c, 'bar'),
                'forecast' => $this->chartSlide($section->title, $c, 'forecast'),
                'root_cause' => $this->rootCauseSlide($section->title, $c),
                'anomalies' => $this->bulletSlide($section->title, array_map(fn ($a) => "{$a['label']} — ".date('j M', strtotime($a['period'])).': '.Format::value($a['actual'], $a['format']).' vs expected '.Format::value($a['expected'], $a['format']).' ('.number_format(abs($a['score']), 1).'σ)', $c['items'] ?? []) ?: [$c['empty_message'] ?? '']),
                'risks' => $this->bulletSlide($section->title, array_map(fn ($r) => strtoupper($r['severity']).' · '.$r['title'].' — '.$r['detail'], $c['items'] ?? []) ?: [$c['empty_message'] ?? '']),
                'text' => $this->bulletSlide($section->title, array_filter(explode("\n", $c['markdown'] ?? ''))),
                default => null,
            };
        }

        IOFactory::createWriter($this->deck, 'PowerPoint2007')->save($path);
    }

    private function cover(Report $report): void
    {
        $s = $this->slide(dark: true);
        $this->text($s, strtoupper($report->organisation->name ?? ''), 48, 64, 600, 24, 12, $this->theme['accent'], true);
        $this->text($s, $report->title, 48, 190, 820, 90, 40, 'FFFFFF', true);
        $period = $report->parameters['range_label'] ?? null;
        $this->text($s, trim(($report->subtitle ?? '').($period ? ' · '.$period : '')), 48, 290, 820, 30, 16, 'C9CED8');
        $this->rule($s, 48, 340, 120);
        $this->text($s, 'Generated '.now()->format('j F Y').' · v'.max(1, $report->current_version).' · Every figure is traceable to a governed query', 48, 470, 820, 20, 10, '8B93A3');
    }

    private function kpiSlide(string $title, array $cards): void
    {
        $s = $this->slide();
        $this->title($s, $title);
        $cards = array_slice($cards, 0, 6);
        $cols = count($cards) <= 4 ? max(1, count($cards)) : 3;
        $w = (int) ((864 - ($cols - 1) * 16) / $cols);
        foreach ($cards as $i => $k) {
            $x = 48 + ($i % $cols) * ($w + 16);
            $y = 130 + intdiv($i, $cols) * 170;
            $box = $this->text($s, '', $x, $y, $w, 150, 10, $this->theme['text']);
            $box->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.'F2F3F6'));
            $this->text($s, strtoupper($k['label']), $x + 16, $y + 14, $w - 32, 18, 10, $this->theme['muted'], true);
            $this->text($s, Format::value($k['value'], $k['format']), $x + 16, $y + 40, $w - 32, 50, 30, $this->theme['ink'], true);
            $color = match ($k['sentiment']) { 'positive' => $this->theme['positive'], 'negative' => $this->theme['negative'], default => $this->theme['muted'] };
            $arrow = match ($k['direction']) { 'up' => '▲ ', 'down' => '▼ ', default => '' };
            $this->text($s, $arrow.Format::change($k['change'], $k['change_pct'], $k['format']).' vs previous', $x + 16, $y + 98, $w - 32, 20, 11, $color, true);
            if ($k['target'] !== null) {
                $this->text($s, 'Target '.Format::value($k['target'], $k['format']).' · '.($k['target_status'] === 'met' ? 'met' : 'missed'), $x + 16, $y + 120, $w - 32, 18, 9, $this->theme['muted']);
            }
        }
    }

    private function chartSlide(string $title, array $c, string $kind): void
    {
        $s = $this->slide();
        $this->title($s, $title);
        if (! empty($c['caption'])) {
            $this->text($s, $c['caption'], 48, 100, 864, 24, 12, $this->theme['muted']);
        }

        $chart = $s->createChartShape()->setOffsetX(48)->setOffsetY(130)->setWidth(864)->setHeight(370);
        $chart->getTitle()->setVisible(false);
        $chart->getLegend()->setVisible($kind === 'forecast');
        $label = fn ($p) => date(strlen((string) $p) >= 10 ? 'M y' : 'M', strtotime((string) $p));

        if ($kind === 'bar') {
            $data = [];
            foreach (array_reverse($c['rows'] ?? []) as $r) {
                $data[(string) $r['member']] = round((float) $r['value'], 4);
            }
            $type = new Bar;
            $type->setBarDirection(Bar::DIRECTION_HORIZONTAL);
            $series = new Series($c['label'] ?? 'Value', $data);
            $series->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.$this->theme['series'][0]));
            $series->setShowValue(false);
            $type->addSeries($series);
        } elseif ($kind === 'forecast') {
            $type = new Line;
            $hist = [];
            $fc = [];
            foreach ($c['history'] ?? [] as $p) {
                $hist[$label($p['period'])] = round((float) $p['value'], 4);
            }
            foreach ($c['points'] ?? [] as $p) {
                $fc[$label($p['period'])] = round((float) $p['value'], 4);
            }
            $all = array_fill_keys(array_keys($hist + $fc), null);
            $h = new Series('Actual', array_merge($all, $hist));
            $f = new Series('Forecast', array_merge($all, $fc));
            foreach ([[$h, $this->theme['series'][0]], [$f, $this->theme['accent']]] as [$series, $col]) {
                $this->outline($series)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.$col));
                $this->outline($series)->setWidth(28575);
                $series->getMarker()->setSymbol('none');
                $series->setShowValue(false);
                $type->addSeries($series);
            }
        } else {
            $data = [];
            foreach ($c['series'] ?? [] as $p) {
                $data[$label($p['period'])] = $p['value'] === null ? null : round((float) $p['value'], 4);
            }
            $type = ($c['chart'] ?? 'line') === 'area' ? new Area : new Line;
            $series = new Series($c['label'] ?? 'Value', $data);
            $series->setShowValue(false);
            if ($type instanceof Area) {
                $series->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.$this->theme['series'][0]));
            } else {
                $this->outline($series)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.$this->theme['series'][0]));
                $this->outline($series)->setWidth(28575);
                $series->getMarker()->setSymbol('none');
            }
            $type->addSeries($series);
        }
        $chart->getPlotArea()->setType($type);
    }

    private function outline(Series $series): \PhpOffice\PhpPresentation\Style\Outline
    {
        if ($series->getOutline() === null) {
            $series->setOutline(new \PhpOffice\PhpPresentation\Style\Outline);
        }

        return $series->getOutline();
    }

    private function rootCauseSlide(string $title, array $c): void
    {
        $s = $this->slide();
        $this->title($s, $title);
        $this->text($s, strtoupper($c['label'] ?? ''), 48, 110, 400, 20, 11, $this->theme['muted'], true);
        $color = ($c['sentiment'] ?? '') === 'negative' ? $this->theme['negative'] : $this->theme['positive'];
        $this->text($s, Format::change($c['change'] ?? null, $c['change_pct'] ?? null, $c['format'] ?? 'number'), 48, 132, 400, 50, 34, $color, true);
        $this->text($s, Format::value($c['previous']['value'] ?? null, $c['format'] ?? 'number').' → '.Format::value($c['current']['value'] ?? null, $c['format'] ?? 'number'), 48, 186, 400, 22, 13, $this->theme['muted']);
        if (! empty($c['onset']['date'])) {
            $this->text($s, 'Shift began around '.date('j M Y', strtotime($c['onset']['date'])), 48, 214, 400, 22, 12, $this->theme['text']);
        }
        $this->text($s, 'MAIN DRIVERS', 500, 110, 400, 20, 11, $this->theme['muted'], true);
        foreach (array_slice($c['drivers'] ?? [], 0, 5) as $i => $d) {
            $y = 138 + $i * 66;
            $box = $this->text($s, '', 500, $y, 412, 56, 10, $this->theme['text']);
            $box->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFF2F3F6'));
            $this->text($s, $d['dimension_label'].' · '.$d['member'], 514, $y + 6, 300, 22, 13, $this->theme['ink'], true);
            $this->text($s, Format::value($d['previous_value'], $c['format']).' → '.Format::value($d['current_value'], $c['format']), 514, $y + 30, 260, 20, 11, $this->theme['muted']);
            $this->text($s, round(($d['impact_share'] ?? 0) * 100).'% of change', 780, $y + 16, 120, 22, 13, $color, true);
        }
        $this->text($s, 'Method: leave-one-out counterfactual attribution over governed semantic queries.', 48, 492, 864, 18, 9, $this->theme['muted']);
    }

    private function bulletSlide(string $title, array $lines): void
    {
        $s = $this->slide();
        $this->title($s, $title);
        $shape = $s->createRichTextShape()->setOffsetX(48)->setOffsetY(120)->setWidth(864)->setHeight(380);
        foreach (array_values(array_filter($lines)) as $i => $line) {
            $p = $i === 0 ? $shape->getActiveParagraph() : $shape->createParagraph();
            $p->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $p->setSpacingAfter(14);
            $bullet = $p->createTextRun('— ');
            $bullet->getFont()->setColor(new Color('FF'.$this->theme['accent']))->setBold(true)->setSize(15);
            $run = $p->createTextRun($line);
            $run->getFont()->setSize(15)->setColor(new Color('FF'.$this->theme['text']))->setName('Calibri');
        }
    }

    private function slide(bool $dark = false): Slide
    {
        $s = $this->deck->createSlide();
        $this->slideNo++;
        $bg = new BackgroundColor;
        $bg->setColor(new Color('FF'.($dark ? $this->theme['ink'] : $this->theme['paper'])));
        $s->setBackground($bg);
        if (! $dark) {
            $this->text($s, (string) $this->slideNo, 880, 506, 40, 16, 9, $this->theme['muted']);
        }

        return $s;
    }

    private function title(Slide $s, string $title): void
    {
        $this->rule($s, 48, 44, 48);
        $this->text($s, $title, 48, 52, 864, 44, 26, $this->theme['ink'], true);
    }

    private function rule(Slide $s, int $x, int $y, int $w): void
    {
        $r = $s->createRichTextShape()->setOffsetX($x)->setOffsetY($y)->setWidth($w)->setHeight(4);
        $r->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.$this->theme['accent']));
    }

    private function text(Slide $s, string $text, int $x, int $y, int $w, int $h, int $size, string $color, bool $bold = false): RichText
    {
        $shape = $s->createRichTextShape()->setOffsetX($x)->setOffsetY($y)->setWidth($w)->setHeight($h);
        $shape->setInsetLeft(0)->setInsetRight(0)->setInsetTop(0)->setInsetBottom(0);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        if ($text !== '') {
            $run = $shape->createTextRun($text);
            $run->getFont()->setSize($size)->setBold($bold)->setColor(new Color('FF'.$color))->setName('Calibri');
        }

        return $shape;
    }

    public function extension(): string
    {
        return 'pptx';
    }

    public function mime(): string
    {
        return 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    }
}
