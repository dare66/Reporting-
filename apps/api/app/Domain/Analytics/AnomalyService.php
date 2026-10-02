<?php

namespace App\Domain\Analytics;

use App\Domain\Notifications\Notifier;
use App\Domain\Query\QueryService;
use App\Domain\Query\SemanticQuery;
use App\Domain\Query\TimeRange;
use App\Domain\Semantic\CatalogRepository;
use App\Models\Anomaly;
use App\Models\User;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Scans KPI daily series for anomalies, persists them (idempotently) and turns
 * fresh, severe ones into "push intelligence" — notifications that already say
 * what moved and the leading driver.
 */
class AnomalyService
{
    public const DEFAULT_METRICS = [
        'decisions.rejection_rate', 'decisions.sla_compliance', 'decisions.avg_processing_days',
        'applications.total_applications', 'applications.high_risk_share', 'revenue.revenue',
    ];

    public function __construct(
        private readonly QueryService $queries,
        private readonly CatalogRepository $catalogs,
        private readonly AnalyticsEngineClient $engine,
        private readonly RootCauseService $rootCause,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  list<string>|null  $refs  metric refs to scan; defaults to every KPI
     * @return list<Anomaly> newly detected anomalies
     */
    public function scan(User $user, ?array $refs = null, int $lookbackDays = 120, int $reportDays = 30): array
    {
        $now = CarbonImmutable::now()->startOfDay();
        $window = new TimeRange($now->subDays($lookbackDays), $now, 'scan'); // exclude today (partial)
        $new = [];

        foreach ($refs ?? self::DEFAULT_METRICS as $ref) {
            $r = MetricRef::parse($ref);
            $catalog = $this->catalogs->get($r->model);
            $metric = $catalog->metric($r->metric);
            $res = $this->queries->runOn($catalog, new SemanticQuery(metrics: [$r->metric], grain: 'day', timeRange: $window, limit: 1000), $user);
            $series = array_map(fn ($row) => ['period' => $row['period'], 'value' => $row[$r->metric]], $res->rows);
            if (count($series) < 28) {
                continue;
            }

            $detected = $this->engine->anomalies($series, 'day');
            foreach ($detected['anomalies'] as $a) {
                if (CarbonImmutable::parse($a['period']) < $now->subDays($reportDays)) {
                    continue;
                }
                $severity = abs($a['score']) >= 4.5 ? 'critical' : (abs($a['score']) >= 3.5 ? 'high' : 'medium');
                $existing = Anomaly::where(['metric_key' => $ref, 'period' => $a['period'], 'grain' => 'day'])->first();
                $anomaly = Anomaly::updateOrCreate(
                    ['organisation_id' => $user->organisation_id, 'metric_key' => $ref, 'period' => $a['period'], 'grain' => 'day'],
                    [
                        'semantic_model_id' => $catalog->id, 'expected' => $a['expected'], 'actual' => $a['actual'],
                        'lower' => $a['lower'], 'upper' => $a['upper'], 'score' => $a['score'], 'method' => $detected['method'],
                        'severity' => $severity,
                        'evidence' => ['label' => $metric['label'], 'format' => $metric['format'], 'direction' => $a['actual'] > $a['expected'] ? 'above' : 'below',
                            'higher_is_better' => $metric['higher_is_better'], 'query' => $res->evidence()],
                    ],
                );
                if (! $existing) {
                    $new[] = $anomaly;
                }
            }
        }

        $this->notifyMostSevere($new, $user);

        return $new;
    }

    /** @param array<int, Anomaly> $anomalies */
    private function notifyMostSevere(array $anomalies, User $user): void
    {
        usort($anomalies, fn ($a, $b) => abs($b->score) <=> abs($a->score));
        $top = $anomalies[0] ?? null;
        if (! $top) {
            return;
        }
        $label = $top->evidence['label'];
        $fmt = fn ($v) => Format::value($v, $top->evidence['format']);
        $driver = '';
        try {
            $rc = $this->rootCause->explain($top->metric_key, 'last_30_days', $user);
            if ($d = $rc['drivers'][0] ?? null) {
                $driver = " Main driver: {$d['dimension_label']} = {$d['member']}.";
            }
        } catch (Throwable) {
        }
        $sd = number_format(abs($top->score), 1);
        $this->notifier->toPermission('dashboards.view', $user->organisation_id, [
            'type' => 'anomaly',
            'severity' => $top->severity === 'critical' ? 'critical' : 'warning',
            'title' => "{$label} is {$sd} standard deviations {$top->evidence['direction']} normal",
            'body' => "{$label} was {$fmt($top->actual)} on {$top->period->format('j M')} against an expected {$fmt($top->expected)}.{$driver} Tap to investigate.",
            'link' => '/investigate?metric='.urlencode($top->metric_key).'&anomaly='.$top->id,
            'data' => ['anomaly_id' => $top->id, 'metric' => $top->metric_key],
            'channels' => ['in_app', 'push'],
        ]);
    }
}
