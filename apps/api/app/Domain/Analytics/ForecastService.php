<?php

namespace App\Domain\Analytics;

use App\Domain\Query\QueryService;
use App\Domain\Query\SemanticQuery;
use App\Domain\Query\TimeRange;
use App\Domain\Semantic\CatalogRepository;
use App\Models\Forecast;
use App\Models\User;
use Carbon\CarbonImmutable;

class ForecastService
{
    public const HORIZONS = ['7d' => ['day', 7], '30d' => ['day', 30], '90d' => ['week', 13], '6m' => ['month', 6], '12m' => ['month', 12]];

    public function __construct(
        private readonly QueryService $queries,
        private readonly CatalogRepository $catalogs,
        private readonly AnalyticsEngineClient $engine,
    ) {}

    public function forecast(string $ref, string $horizonKey, User $user, array $filters = []): Forecast
    {
        [$grain, $horizon] = self::HORIZONS[$horizonKey] ?? self::HORIZONS['6m'];
        $r = MetricRef::parse($ref);
        $catalog = $this->catalogs->get($r->model);
        $metric = $catalog->metric($r->metric);

        // Only complete periods train the model; the running period is excluded
        // so a partial month never reads as a collapse.
        $now = CarbonImmutable::now();
        $end = match ($grain) {
            'day' => $now->startOfDay(),
            'week' => $now->startOfWeek(),
            default => $now->startOfMonth(),
        };
        $start = match ($grain) {
            'day' => $end->subDays(180),
            'week' => $end->subWeeks(104),
            default => $end->subMonths(24),
        };
        $result = $this->queries->runOn($catalog, new SemanticQuery(
            metrics: [$r->metric], filters: $filters, grain: $grain, timeRange: new TimeRange($start, $end, 'history'), limit: 1000,
        ), $user);
        $history = array_map(fn ($row) => ['period' => $row['period'], 'value' => $row[$r->metric]], $result->rows);

        $out = $this->engine->forecast($history, $grain, $horizon);

        return Forecast::create([
            'semantic_model_id' => $catalog->id,
            'metric_key' => $r->metric,
            'grain' => $grain,
            'horizon' => $horizon,
            'method' => $out['method'],
            'history' => $history,
            'points' => $out['points'],
            'diagnostics' => $out['diagnostics'] + [
                'label' => $metric['label'], 'format' => $metric['format'], 'ref' => $ref,
                'evidence' => $result->evidence(), 'horizon_key' => $horizonKey,
            ],
            'created_by' => $user->id,
        ]);
    }
}
