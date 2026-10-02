<?php

namespace App\Domain\Analytics;

use App\Domain\Query\QueryService;
use App\Domain\Query\SemanticQuery;
use App\Domain\Query\TimeRange;
use App\Domain\Semantic\CatalogRepository;
use App\Models\User;

/**
 * Period-over-period KPI cards with sparklines, targets and evidence.
 * Queries are batched per semantic model (3 queries per model).
 */
class KpiService
{
    public function __construct(private readonly QueryService $queries, private readonly CatalogRepository $catalogs) {}

    /**
     * @param  array<string>  $refs
     * @param  array<int, array<string, mixed>>  $filters  applied to every model that has the dimension
     * @return array<int, array<string, mixed>>
     */
    public function cards(array $refs, string|array $range, User $user, array $filters = [], string $compare = 'previous_period'): array
    {
        $current = TimeRange::resolve($range);
        $previous = $compare === 'previous_year' ? $current->previousYear() : $current->previous();
        $grain = self::sparkGrain($current);

        $byModel = [];
        foreach ($refs as $ref) {
            $r = MetricRef::parse($ref);
            $byModel[$r->model][] = $r->metric;
        }

        $cards = [];
        foreach ($byModel as $modelKey => $metrics) {
            $catalog = $this->catalogs->get($modelKey);
            $applicable = array_values(array_filter($filters, fn ($f) => isset($catalog->dimensions[$f['dimension']])));
            $q = new SemanticQuery(metrics: $metrics, filters: $applicable, timeRange: $current);
            $cur = $this->queries->runOn($catalog, $q, $user);
            $prev = $this->queries->runOn($catalog, $q->withTimeRange($previous), $user);
            // Sparklines give context beyond the selected period: ~12 buckets, ending with the current one.
            $sparkRange = new TimeRange(match ($grain) {
                'month' => $current->from->subMonths(11)->startOfMonth(),
                'week' => $current->to->subWeeks(12)->startOfWeek(),
                default => $current->from,
            }, $current->to, 'spark');
            $spark = $this->queries->runOn($catalog, new SemanticQuery(metrics: $metrics, filters: $applicable, grain: $grain, timeRange: $sparkRange), $user);

            foreach ($metrics as $key) {
                $meta = $catalog->metric($key);
                $value = $cur->first()[$key] ?? null;
                $before = $prev->first()[$key] ?? null;
                $cards[] = [
                    'ref' => "{$modelKey}.{$key}",
                    'model' => $modelKey,
                    'metric' => $key,
                    'label' => $meta['label'],
                    'description' => $meta['description'],
                    'format' => $meta['format'],
                    'higher_is_better' => $meta['higher_is_better'],
                    'value' => $value,
                    'previous' => $before,
                    ...self::change($value, $before, $meta['higher_is_better']),
                    'target' => $meta['target'],
                    'target_status' => self::targetStatus($value, $meta['target'], $meta['higher_is_better']),
                    'sparkline' => array_map(fn ($r) => ['period' => $r['period'], 'value' => $r[$key]], $spark->rows),
                    'sparkline_grain' => $grain,
                    'period' => $current->toArray(),
                    'previous_period' => $previous->toArray(),
                    'evidence' => ['current' => $cur->evidence(), 'previous' => $prev->evidence()],
                    'executed_at' => $cur->executedAt,
                    'cached' => $cur->cached,
                ];
            }
        }

        // Preserve the caller's ordering.
        $order = array_flip($refs);
        usort($cards, fn ($a, $b) => $order[$a['ref']] <=> $order[$b['ref']]);

        return $cards;
    }

    /** @return array{change: ?float, change_pct: ?float, direction: string, sentiment: string} */
    public static function change(?float $value, ?float $previous, bool $higherIsBetter): array
    {
        if ($value === null || $previous === null) {
            return ['change' => null, 'change_pct' => null, 'direction' => 'flat', 'sentiment' => 'neutral'];
        }
        $change = $value - $previous;
        $pct = $previous != 0.0 ? $change / abs($previous) : null;
        $direction = abs($pct ?? $change) < 0.001 ? 'flat' : ($change > 0 ? 'up' : 'down');
        $sentiment = $direction === 'flat' ? 'neutral' : (($direction === 'up') === $higherIsBetter ? 'positive' : 'negative');

        return ['change' => round($change, 6), 'change_pct' => $pct === null ? null : round($pct, 6), 'direction' => $direction, 'sentiment' => $sentiment];
    }

    public static function targetStatus(?float $value, ?float $target, bool $higherIsBetter): ?string
    {
        if ($value === null || $target === null) {
            return null;
        }

        return ($higherIsBetter ? $value >= $target : $value <= $target) ? 'met' : 'missed';
    }

    public static function sparkGrain(TimeRange $range): string
    {
        $days = $range->from->diffInDays($range->to);

        // Daily buckets are dominated by weekday seasonality; weekly is the honest short-range shape.
        return match (true) {
            $days <= 10 => 'day',
            $days <= 200 => 'week',
            default => 'month',
        };
    }
}
