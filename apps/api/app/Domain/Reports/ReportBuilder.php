<?php

namespace App\Domain\Reports;

use App\Domain\Analytics\Format;
use App\Domain\Analytics\ForecastService;
use App\Domain\Analytics\KpiService;
use App\Domain\Analytics\MetricRef;
use App\Domain\Analytics\RootCauseService;
use App\Domain\Query\QueryService;
use App\Domain\Query\SemanticQuery;
use App\Domain\Query\TimeRange;
use App\Domain\Semantic\CatalogRepository;
use App\Models\Anomaly;
use App\Models\Report;
use App\Models\ReportSection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Builds report sections from blueprints. Each section's content is computed
 * from governed queries and stores its evidence; the executive summary and
 * risks are written last from the facts gathered by the other sections.
 */
class ReportBuilder
{
    /** @var array<int, array<string, mixed>> facts collected while building */
    private array $facts = [];

    public function __construct(
        private readonly KpiService $kpis,
        private readonly RootCauseService $rootCause,
        private readonly ForecastService $forecasts,
        private readonly QueryService $queries,
        private readonly CatalogRepository $catalogs,
    ) {}

    /** @param array<int, array<string, mixed>> $blueprints */
    public function build(Report $report, array $blueprints, User $user): Report
    {
        $range = $report->parameters['range'] ?? 'last_30_days';
        $this->facts = [];

        $built = [];
        foreach ($blueprints as $i => $bp) {
            if (in_array($bp['type'], ['summary', 'risks'], true)) {
                $built[$i] = null; // second pass

                continue;
            }
            $built[$i] = $this->safely($bp, fn () => $this->section($bp, $range, $user));
        }
        foreach ($blueprints as $i => $bp) {
            if ($built[$i] === null) {
                $built[$i] = $bp['type'] === 'summary' ? $this->summary($report, $range) : $this->risks();
            }
        }

        DB::transaction(function () use ($report, $blueprints, $built) {
            $report->sections()->delete();
            foreach ($blueprints as $i => $bp) {
                $report->sections()->create(['position' => $i, 'type' => $bp['type'], 'title' => $bp['title'], 'content' => $built[$i] + ['blueprint' => $bp]]);
            }
            $report->touch();
        });

        return $report->load('sections');
    }

    /** Recomputes one section in place (used by "refresh" and conversational edits). */
    public function rebuildSection(ReportSection $section, User $user): ReportSection
    {
        $bp = $section->content['blueprint'] ?? ['type' => $section->type, 'title' => $section->title];
        $range = $section->report->parameters['range'] ?? 'last_30_days';
        $section->update(['content' => $this->safely($bp, fn () => $this->section($bp, $range, $user)) + ['blueprint' => $bp]]);

        return $section;
    }

    private function section(array $bp, string|array $range, User $user): array
    {
        return match ($bp['type']) {
            'kpis' => $this->kpiSection($bp, $range, $user),
            'chart' => $this->chartSection($bp, $user),
            'breakdown' => $this->breakdownSection($bp, $range, $user),
            'anomalies' => $this->anomalySection($bp),
            'root_cause' => $this->rootCauseSection($bp, $range, $user),
            'forecast' => $this->forecastSection($bp, $user),
            'text' => ['markdown' => $bp['markdown'] ?? ''],
            default => ['error' => "Unknown section type {$bp['type']}"],
        };
    }

    private function kpiSection(array $bp, string|array $range, User $user): array
    {
        $cards = $this->kpis->cards($bp['metrics'], $range, $user);
        foreach ($cards as $c) {
            $this->facts[] = ['type' => 'kpi', 'ref' => $c['ref'], 'label' => $c['label'], 'format' => $c['format'], 'value' => $c['value'], 'previous' => $c['previous'],
                'change' => $c['change'], 'change_pct' => $c['change_pct'], 'sentiment' => $c['sentiment'], 'target_status' => $c['target_status'], 'period' => $c['period']['label']];
        }

        return ['period' => $cards[0]['period'] ?? null, 'cards' => array_map(fn ($c) => array_diff_key($c, ['evidence' => 1, 'description' => 1]), $cards),
            'evidence' => array_map(fn ($c) => ['ref' => $c['ref'], 'query_hash' => $c['evidence']['current']['query_hash']], $cards)];
    }

    private function chartSection(array $bp, User $user): array
    {
        $ref = MetricRef::parse($bp['metric']);
        $catalog = $this->catalogs->get($ref->model);
        $metric = $catalog->metric($ref->metric);
        $res = $this->queries->runOn($catalog, new SemanticQuery(metrics: [$ref->metric], grain: $bp['grain'] ?? 'month', timeRange: TimeRange::resolve($bp['range'] ?? 'last_12_months'), limit: 500), $user);
        $series = array_map(fn ($r) => ['period' => $r['period'], 'value' => $r[$ref->metric]], $res->completeRows());

        return ['metric' => $bp['metric'], 'label' => $metric['label'], 'format' => $metric['format'], 'grain' => $bp['grain'] ?? 'month',
            'chart' => $bp['chart'] ?? (($bp['grain'] ?? 'month') === 'month' ? 'area' : 'line'), 'series' => $series, 'target' => $metric['target'],
            'caption' => $this->trendCaption($metric, $series), 'evidence' => [$res->evidence()]];
    }

    private function breakdownSection(array $bp, string|array $range, User $user): array
    {
        $ref = MetricRef::parse($bp['metric']);
        $catalog = $this->catalogs->get($ref->model);
        $metric = $catalog->metric($ref->metric);
        $res = $this->queries->runOn($catalog, new SemanticQuery(metrics: [$ref->metric], dimensions: [$bp['dimension']], timeRange: TimeRange::resolve($range),
            sort: [['key' => $ref->metric, 'dir' => $bp['sort'] ?? 'desc']], limit: $bp['limit'] ?? 10), $user);
        $rows = array_map(fn ($r) => ['member' => $r[$bp['dimension']], 'value' => $r[$ref->metric]], $res->rows);
        if ($rows) {
            $this->facts[] = ['type' => 'breakdown', 'label' => $metric['label'], 'dimension' => $catalog->dimension($bp['dimension'])['label'], 'top' => $rows[0], 'format' => $metric['format'], 'sort' => $bp['sort'] ?? 'desc'];
        }

        return ['metric' => $bp['metric'], 'label' => $metric['label'], 'format' => $metric['format'], 'dimension' => $bp['dimension'],
            'dimension_label' => $catalog->dimension($bp['dimension'])['label'], 'chart' => 'bar', 'rows' => $rows, 'evidence' => [$res->evidence()]];
    }

    private function anomalySection(array $bp): array
    {
        $items = Anomaly::whereIn('metric_key', $bp['metrics'] ?? [])->where('period', '>=', now()->subDays(45))
            ->orderByRaw('ABS(score) DESC')->limit(6)->get()
            ->map(fn ($a) => ['id' => $a->id, 'metric' => $a->metric_key, 'label' => $a->evidence['label'], 'format' => $a->evidence['format'], 'period' => $a->period->toDateString(),
                'expected' => $a->expected, 'actual' => $a->actual, 'score' => $a->score, 'severity' => $a->severity, 'direction' => $a->evidence['direction'], 'status' => $a->status])->all();
        foreach (array_slice($items, 0, 2) as $a) {
            $this->facts[] = ['type' => 'anomaly'] + $a;
        }

        return ['items' => $items, 'empty_message' => $items ? null : 'No statistically significant anomalies were detected in the last 45 days.'];
    }

    private function rootCauseSection(array $bp, string|array $range, User $user): array
    {
        $rc = $this->rootCause->explain($bp['metric'], $range, $user);
        if ($d = $rc['drivers'][0] ?? null) {
            $this->facts[] = ['type' => 'driver', 'label' => $rc['label'], 'format' => $rc['format'], 'change' => $rc['change'], 'sentiment' => $rc['sentiment'],
                'dimension' => $d['dimension_label'], 'member' => $d['member'], 'impact_share' => $d['impact_share'], 'onset' => $rc['onset']['date'] ?? null];
        }
        unset($rc['onset']['series']);

        return ['metric' => $bp['metric']] + array_diff_key($rc, ['evidence' => 1, 'dimensions' => 1])
            + ['dimensions' => array_map(fn ($d) => ['key' => $d['key'], 'label' => $d['label'], 'explained_share' => $d['explained_share'], 'members' => array_slice($d['members'], 0, 5)], array_slice($rc['dimensions'], 0, 4)),
                'evidence' => array_map(fn ($e) => ['query_hash' => $e['query_hash']], $rc['evidence'])];
    }

    private function forecastSection(array $bp, User $user): array
    {
        $horizon = ($bp['horizon'] ?? 6) >= 12 ? '12m' : '6m';
        $f = $this->forecasts->forecast($bp['metric'], $horizon, $user);
        $last = end($f->points) ?: null;
        if ($last) {
            $this->facts[] = ['type' => 'forecast', 'label' => $f->diagnostics['label'], 'format' => $f->diagnostics['format'], 'period' => $last['period'], 'value' => $last['value'], 'lower' => $last['lower'], 'upper' => $last['upper']];
        }

        return ['metric' => $bp['metric'], 'label' => $f->diagnostics['label'], 'format' => $f->diagnostics['format'], 'grain' => $f->grain,
            'method' => $f->method, 'history' => array_slice($f->history, -12), 'points' => $f->points, 'forecast_id' => $f->id,
            'diagnostics' => array_diff_key($f->diagnostics, ['evidence' => 1])];
    }

    private function summary(Report $report, string|array $range): array
    {
        $period = TimeRange::resolve($range)->toArray();
        $kpis = array_filter($this->facts, fn ($f) => $f['type'] === 'kpi');
        $good = array_filter($kpis, fn ($f) => $f['sentiment'] === 'positive');
        $bad = array_filter($kpis, fn ($f) => $f['sentiment'] === 'negative');
        $paragraphs = [];

        $line = fn ($f) => "{$f['label']} ".Format::value($f['value'], $f['format']).' ('.Format::change($f['change'], $f['change_pct'], $f['format']).')';
        if ($kpis) {
            $paragraphs[] = 'For '.Format::period($period['label']).' ('.$period['from'].' to '.$period['to'].'), '.count($good).' of '.count($kpis).' headline indicators improved'
                .($bad ? ' and '.count($bad).' deteriorated.' : '.');
        }
        if ($good) {
            $paragraphs[] = 'Improving: '.implode('; ', array_map($line, array_slice($good, 0, 3))).'.';
        }
        if ($bad) {
            $paragraphs[] = 'Requiring attention: '.implode('; ', array_map($line, array_slice($bad, 0, 3))).'.';
        }
        foreach (array_filter($this->facts, fn ($f) => $f['type'] === 'driver') as $d) {
            $paragraphs[] = "The change in {$d['label']} (".Format::change($d['change'], null, $d['format']).") is led by {$d['dimension']} “{$d['member']}”, which accounts for "
                .round(($d['impact_share'] ?? 0) * 100).'% of the movement'.($d['onset'] ? ', beginning around '.date('j M', strtotime($d['onset'])) : '').'.';
        }
        foreach (array_filter($this->facts, fn ($f) => $f['type'] === 'forecast') as $f) {
            $paragraphs[] = "{$f['label']} is projected at ".Format::value($f['value'], $f['format']).' by '.date('M Y', strtotime($f['period']))
                .' (80% interval '.Format::value($f['lower'], $f['format']).'–'.Format::value($f['upper'], $f['format']).').';
        }

        return ['paragraphs' => $paragraphs, 'period' => $period, 'generated_from' => count($this->facts).' computed facts', 'facts' => $this->facts];
    }

    private function risks(): array
    {
        $items = [];
        foreach ($this->facts as $f) {
            if ($f['type'] === 'kpi' && ($f['target_status'] === 'missed' || $f['sentiment'] === 'negative')) {
                $items[] = ['severity' => $f['target_status'] === 'missed' ? 'high' : 'medium', 'title' => "{$f['label']} ".($f['target_status'] === 'missed' ? 'below target' : 'deteriorating'),
                    'detail' => Format::value($f['value'], $f['format']).' ('.Format::change($f['change'], $f['change_pct'], $f['format']).' vs previous period).'];
            }
            if ($f['type'] === 'anomaly') {
                $items[] = ['severity' => $f['severity'] === 'critical' ? 'high' : 'medium', 'title' => "Unusual {$f['label']} on ".date('j M', strtotime($f['period'])),
                    'detail' => 'Actual '.Format::value($f['actual'], $f['format']).' vs expected '.Format::value($f['expected'], $f['format']).' ('.number_format(abs($f['score']), 1).'σ).'];
            }
            if ($f['type'] === 'driver' && $f['sentiment'] === 'negative') {
                $items[] = ['severity' => 'medium', 'title' => "Concentrated driver: {$f['member']}", 'detail' => "{$f['dimension']} “{$f['member']}” explains ".round(($f['impact_share'] ?? 0) * 100)."% of the {$f['label']} change; targeted action is likely more effective than broad measures."];
            }
        }

        return ['items' => array_slice($items, 0, 8), 'empty_message' => $items ? null : 'No material risks were identified from the analysed indicators.'];
    }

    private function trendCaption(array $metric, array $series): ?string
    {
        $vals = array_values(array_filter(array_column($series, 'value'), fn ($v) => $v !== null));
        if (count($vals) < 3) {
            return null;
        }
        $first = $vals[0];
        $last = end($vals);
        $max = max($vals);
        $peakAt = $series[array_search($max, array_column($series, 'value'))]['period'] ?? null;

        return "{$metric['label']} moved from ".Format::value($first, $metric['format']).' to '.Format::value($last, $metric['format'])
            .' across complete periods, peaking at '.Format::value($max, $metric['format']).($peakAt ? ' in '.date('M Y', strtotime($peakAt)) : '').'.';
    }

    private function safely(array $bp, callable $fn): array
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            report($e);

            return ['error' => "This section couldn't be generated: ".$e->getMessage()];
        }
    }
}
