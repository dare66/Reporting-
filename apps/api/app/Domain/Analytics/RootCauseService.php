<?php

namespace App\Domain\Analytics;

use App\Domain\Query\Expression\Node;
use App\Domain\Query\QueryResult;
use App\Domain\Query\QueryService;
use App\Domain\Query\QueryValidationException;
use App\Domain\Query\SemanticQuery;
use App\Domain\Query\TimeRange;
use App\Domain\Semantic\Catalog;
use App\Domain\Semantic\CatalogRepository;
use App\Models\User;

/**
 * Explains why a metric changed between two periods.
 *
 * Method — leave-one-out counterfactual decomposition: for each member m of a
 * dimension, impact(m) = V(current) − V(current with m's components reverted to
 * the comparison period). V is the metric expression evaluated over additive
 * components (avg measures are decomposed into sum ÷ count), so impacts are exact
 * for additive metrics and a first-order attribution for ratios. Every number
 * returned is derived from queries listed in `evidence`.
 *
 * @phpstan-import-type Evidence from QueryResult
 *
 * @phpstan-type Components array<string, array{measure: string, role: string}>
 * @phpstan-type MemberImpact array{member: string, current_value: ?float, previous_value: ?float, current_volume: float, previous_volume: float, impact: float, impact_share: ?float, volume_share: float, excess_impact: float}
 * @phpstan-type Driver array{dimension: string, dimension_label: string, member: string, current_value: ?float, previous_value: ?float, current_volume: float, previous_volume: float, impact: float, impact_share: ?float, volume_share: float, excess_impact: float}
 * @phpstan-type DimensionBreakdown array{key: string, label: string, explained_share: float, member_count: int, members: list<MemberImpact>}
 * @phpstan-type Onset array{date: string, before_mean: float, after_mean: float, shift_sigma: ?float, significant: bool, series: list<array{date: string, value: float}>}
 * @phpstan-type Period array{value: ?float, period: array<string, mixed>}
 * @phpstan-type Explanation array{ref: string, metric: string, label: string, format: string, higher_is_better: bool, current: Period, previous: Period, change: ?float, change_pct: ?float, direction: string, sentiment: string, drivers: list<Driver>, dimensions: list<DimensionBreakdown>, onset: Onset|null, method: string, evidence: list<Evidence>}
 */
class RootCauseService
{
    private const MAX_DIMENSIONS = 8;

    public function __construct(private readonly QueryService $queries, private readonly CatalogRepository $catalogs) {}

    /**
     * @param  string|array<string, mixed>  $range  preset key or explicit {from, to}
     * @param  list<array{dimension: string, op: string, value?: mixed}>  $filters
     * @param  list<string>|null  $dimensions  candidates to test; defaults to the model's root-cause candidates
     * @return Explanation
     */
    public function explain(string $ref, string|array $range, User $user, array $filters = [], ?array $dimensions = null, string $compare = 'previous_period'): array
    {
        $r = MetricRef::parse($ref);
        $catalog = $this->catalogs->get($r->model);
        $metric = $catalog->metric($r->metric);
        $ast = $catalog->metricAst($r->metric);
        [$augmented, $components] = $this->additiveComponents($catalog, $ast);

        $current = TimeRange::resolve($range);
        $previous = $compare === 'previous_year' ? $current->previousYear() : $current->previous();
        $evidence = [];

        $totals = function (TimeRange $t) use ($augmented, $components, $filters, $user, &$evidence) {
            $res = $this->queries->runOn($augmented, new SemanticQuery(metrics: [], measures: array_keys($components), filters: $filters, timeRange: $t), $user);
            $evidence[] = $res->evidence();

            return $this->componentValues($res->first(), $components);
        };
        $curTotals = $totals($current);
        $prevTotals = $totals($previous);
        $vCur = $this->evaluate($ast, $curTotals, $components);
        $vPrev = $this->evaluate($ast, $prevTotals, $components);
        $totalChange = ($vCur !== null && $vPrev !== null) ? $vCur - $vPrev : null;

        $candidates = $dimensions ?? array_keys(array_filter(
            $catalog->dimensions,
            fn ($d) => $d['root_cause_candidate'] && $d['type'] !== 'time' && (! $d['is_sensitive'] || $user->hasPermission('data.sensitive')),
        ));
        $candidates = array_slice($candidates, 0, self::MAX_DIMENSIONS);

        // Volume = the largest additive component (for ratios, the denominator population).
        $volumeKey = array_search(max($curTotals ?: [0]), $curTotals, true) ?: array_key_first($curTotals);
        $dimensionResults = [];
        $allDrivers = [];
        foreach ($candidates as $dimKey) {
            $dim = $catalog->dimension($dimKey);
            $byMember = fn (TimeRange $t) => $this->queries->runOn($augmented, new SemanticQuery(
                metrics: [], measures: array_keys($components), dimensions: [$dimKey], filters: $filters, timeRange: $t, limit: 500,
            ), $user);
            $curRes = $byMember($current);
            $prevRes = $byMember($previous);
            $evidence[] = $curRes->evidence();
            $evidence[] = $prevRes->evidence();

            $members = [];
            foreach ($curRes->rows as $row) {
                $members[(string) $row[$dimKey]]['cur'] = $this->componentValues($row, $components);
            }
            foreach ($prevRes->rows as $row) {
                $members[(string) $row[$dimKey]]['prev'] = $this->componentValues($row, $components);
            }

            $rows = [];
            foreach ($members as $member => $v) {
                $cur = $v['cur'] ?? array_fill_keys(array_keys($curTotals), 0.0);
                $prev = $v['prev'] ?? array_fill_keys(array_keys($curTotals), 0.0);
                $counterfactual = [];
                foreach ($curTotals as $k => $total) {
                    $counterfactual[$k] = $total - $cur[$k] + $prev[$k];
                }
                $vCf = $this->evaluate($ast, $counterfactual, $components);
                $impact = ($vCur !== null && $vCf !== null) ? $vCur - $vCf : 0.0;
                $rows[] = [
                    'member' => $member,
                    'current_value' => $this->evaluate($ast, $cur, $components),
                    'previous_value' => $this->evaluate($ast, $prev, $components),
                    'current_volume' => $cur[$volumeKey] ?? 0.0,
                    'previous_volume' => $prev[$volumeKey] ?? 0.0,
                    'impact' => round($impact, 8),
                    'impact_share' => ($totalChange !== null && abs($totalChange) > 1e-12) ? round($impact / $totalChange, 4) : null,
                ];
            }
            // Excess impact: what a member moved beyond its share of volume. Large
            // segments that merely mirror the overall trend are not "drivers".
            $totalVolume = $curTotals[$volumeKey] ?? 0;
            foreach ($rows as &$row) {
                $volumeShare = $totalVolume > 0 ? $row['current_volume'] / $totalVolume : 0;
                $row['volume_share'] = round($volumeShare, 4);
                $row['excess_impact'] = $totalChange === null ? 0.0 : round($row['impact'] - $volumeShare * $totalChange, 8);
            }
            unset($row);
            usort($rows, fn ($a, $b) => abs($b['excess_impact']) <=> abs($a['excess_impact']));

            $aligned = array_values(array_filter($rows, fn ($m) => $totalChange !== null && $m['excess_impact'] * $totalChange > 0));
            $topExcess = array_sum(array_map(fn ($m) => $m['excess_impact'], array_slice($aligned, 0, 2)));
            $explained = ($totalChange !== null && abs($totalChange) > 1e-12) ? min(1.0, max(0, $topExcess / $totalChange)) : 0;

            $dimensionResults[] = [
                'key' => $dimKey,
                'label' => $dim['label'],
                'explained_share' => round($explained, 4),
                'member_count' => count($rows),
                'members' => array_slice($rows, 0, 8),
            ];
            foreach (array_slice($aligned, 0, 2) as $m) {
                if ($m['volume_share'] < 0.6) {
                    $allDrivers[] = ['dimension' => $dimKey, 'dimension_label' => $dim['label']] + $m;
                }
            }
        }

        usort($dimensionResults, fn ($a, $b) => $b['explained_share'] <=> $a['explained_share']);
        usort($allDrivers, fn ($a, $b) => abs($b['excess_impact']) <=> abs($a['excess_impact']));

        return [
            'ref' => $ref,
            'metric' => $r->metric,
            'label' => $metric['label'],
            'format' => $metric['format'],
            'higher_is_better' => $metric['higher_is_better'],
            'current' => ['value' => $vCur, 'period' => $current->toArray()],
            'previous' => ['value' => $vPrev, 'period' => $previous->toArray()],
            ...KpiService::change($vCur, $vPrev, $metric['higher_is_better']),
            'drivers' => array_slice($allDrivers, 0, 6),
            'dimensions' => $dimensionResults,
            'onset' => $this->onset($augmented, $ast, $components, $current, $previous, $filters, $user, $evidence),
            'method' => 'leave_one_out_counterfactual',
            'evidence' => $evidence,
        ];
    }

    /**
     * Rewrites the metric's measures into additive components and returns an
     * augmented catalog that exposes them as queryable measures.
     *
     * @return array{0: Catalog, 1: array<string, array{measure: string, role: string}>}
     */
    private function additiveComponents(Catalog $catalog, Node $ast): array
    {
        $extra = [];
        $components = [];
        foreach ($ast->references() as $key) {
            $m = $catalog->measure($key);
            switch ($m['aggregation']) {
                case 'count':
                case 'sum':
                    $components[$key] = ['measure' => $key, 'role' => 'value'];
                    break;
                case 'avg':
                    $sumKey = "rc__{$key}__sum";
                    $cntKey = "rc__{$key}__count";
                    $extra[$sumKey] = ['key' => $sumKey, 'label' => $m['label'].' (sum)', 'aggregation' => 'sum', 'field' => $m['field'], 'filters' => $m['filters'], 'description' => null];
                    $extra[$cntKey] = ['key' => $cntKey, 'label' => $m['label'].' (count)', 'aggregation' => 'count', 'field' => $m['field'], 'filters' => $m['filters'], 'description' => null];
                    $components[$sumKey] = ['measure' => $key, 'role' => 'sum'];
                    $components[$cntKey] = ['measure' => $key, 'role' => 'count'];
                    break;
                default:
                    throw new QueryValidationException("Root-cause analysis is not available for '{$m['label']}' because {$m['aggregation']} is not decomposable.");
            }
        }

        $augmented = new Catalog(
            $catalog->id, $catalog->key, $catalog->name, $catalog->baseDatasetId, $catalog->timeDimension, $catalog->datasets,
            $catalog->dimensions, $catalog->measures + $extra, $catalog->metrics, $catalog->relationships, $catalog->policies, $catalog->hierarchies,
        );

        return [$augmented, $components];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Components  $components
     * @return array<string, float>
     */
    private function componentValues(array $row, array $components): array
    {
        $out = [];
        foreach ($components as $key => $_) {
            $out[$key] = (float) ($row['m__'.$key] ?? 0);
        }

        return $out;
    }

    /**
     * @param  array<string, float>  $componentValues
     * @param  Components  $components
     */
    private function evaluate(Node $ast, array $componentValues, array $components): ?float
    {
        $measureValues = [];
        $avg = [];
        foreach ($components as $key => $c) {
            if ($c['role'] === 'value') {
                $measureValues[$c['measure']] = $componentValues[$key] ?? 0.0;
            } else {
                $avg[$c['measure']][$c['role']] = $componentValues[$key] ?? 0.0;
            }
        }
        foreach ($avg as $measure => $parts) {
            $measureValues[$measure] = ($parts['count'] ?? 0) > 0 ? $parts['sum'] / $parts['count'] : null;
        }

        return $ast->evaluate($measureValues);
    }

    /**
     * Finds when the shift began: the split of the daily series (comparison +
     * current window) that maximises the weighted difference in means.
     *
     * @param  Components  $components
     * @param  list<array{dimension: string, op: string, value?: mixed}>  $filters
     * @param  list<Evidence>  $evidence  appended to
     * @return Onset|null
     */
    private function onset(Catalog $catalog, Node $ast, array $components, TimeRange $current, TimeRange $previous, array $filters, User $user, array &$evidence): ?array
    {
        $window = new TimeRange(min($previous->from, $current->from), $current->to, 'onset');
        $res = $this->queries->runOn($catalog, new SemanticQuery(metrics: [], measures: array_keys($components), filters: $filters, grain: 'day', timeRange: $window, limit: 800), $user);
        $evidence[] = $res->evidence();

        $series = [];
        foreach ($res->rows as $row) {
            $v = $this->evaluate($ast, $this->componentValues($row, $components), $components);
            if ($v !== null) {
                $series[] = ['date' => (string) $row['period'], 'value' => $v];
            }
        }
        $n = count($series);
        if ($n < 14) {
            return null;
        }

        $values = array_column($series, 'value');
        $best = null;
        for ($i = 7; $i <= $n - 5; $i++) {
            $before = array_slice($values, 0, $i);
            $after = array_slice($values, $i);
            $mb = array_sum($before) / count($before);
            $ma = array_sum($after) / count($after);
            $score = abs($ma - $mb) * sqrt(count($before) * count($after) / $n);
            if ($best === null || $score > $best['score']) {
                $best = ['index' => $i, 'score' => $score, 'before_mean' => $mb, 'after_mean' => $ma];
            }
        }

        // Significance: compare the shift with day-to-day noise.
        $diffs = [];
        for ($i = 1; $i < $n; $i++) {
            $diffs[] = abs($values[$i] - $values[$i - 1]);
        }
        sort($diffs);
        $noise = ($diffs[intdiv(count($diffs), 2)] ?? 0) / 0.954; // median abs successive difference → sigma
        $significance = $noise > 0 ? abs($best['after_mean'] - $best['before_mean']) / $noise : null;

        return [
            'date' => $series[$best['index']]['date'],
            'before_mean' => round($best['before_mean'], 6),
            'after_mean' => round($best['after_mean'], 6),
            'shift_sigma' => $significance === null ? null : round($significance, 2),
            'significant' => $significance !== null && $significance >= 1.0,
            'series' => $series,
        ];
    }
}
