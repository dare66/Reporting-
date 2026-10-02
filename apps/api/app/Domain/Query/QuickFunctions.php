<?php

namespace App\Domain\Query;

use App\Domain\Query\Dialect\Dialect;
use App\Domain\Semantic\Catalog;

/**
 * Compiles quick functions (% of total, running total, period-over-period…)
 * into SQL window functions over an aggregated metric.
 *
 * Windows are evaluated after GROUP BY and before LIMIT, so a share is a share
 * of the whole result rather than of the rows returned, and the calculation is
 * visible in the evidence SQL like every other number.
 *
 * @phpstan-import-type Calculation from SemanticQuery
 */
final class QuickFunctions
{
    public const LABELS = [
        'percent_of_total' => '% of total',
        'running_sum' => 'Running total',
        'year_to_date' => 'Year to date',
        'difference' => 'Change vs previous',
        'percent_change' => '% change vs previous',
        'moving_average' => 'Moving average',
        'rank' => 'Rank',
    ];

    /** Functions that order rows along the time axis. */
    private const NEEDS_TIME = ['running_sum', 'year_to_date', 'difference', 'percent_change', 'moving_average'];

    /** Functions that add metric values together, which is only meaningful for additive metrics. */
    private const NEEDS_ADDITIVE = ['percent_of_total', 'running_sum', 'year_to_date'];

    /**
     * @param  string|null  $time  SQL of the time bucket, when the query has a grain
     * @param  list<string>  $dimensions  SQL of the grouping dimensions (series partitions)
     */
    public function __construct(
        private readonly Dialect $dialect,
        private readonly Catalog $catalog,
        private readonly ?string $grain,
        private readonly ?string $time,
        private readonly array $dimensions,
    ) {}

    /**
     * @param  Calculation  $calc
     * @param  callable(): string  $metric  returns the metric's aggregate SQL; called once per occurrence so bindings stay in order
     * @return array{0: string, 1: array{key: string, label: string, role: string, type: string, format: string, calc: array{fn: string, metric: string}}}
     */
    public function compile(array $calc, callable $metric): array
    {
        $fn = $calc['fn'];
        $def = $this->catalog->metric($calc['metric']);
        $this->assertApplicable($fn, $calc['metric'], $def['label']);

        $series = $this->dimensions ? 'PARTITION BY '.implode(', ', $this->dimensions).' ' : '';
        $alongTime = "OVER ({$series}ORDER BY {$this->time})";
        // With series, a share is each series' part of its period; a single series shares out the whole range.
        $perPeriod = $this->time !== null && $this->dimensions ? "OVER (PARTITION BY {$this->time})" : 'OVER ()';

        $sql = match ($fn) {
            'percent_of_total' => $metric().' * 1.0 / NULLIF(SUM('.$metric().") {$perPeriod}, 0)",
            'running_sum' => 'SUM('.$metric().") {$alongTime}",
            'year_to_date' => 'SUM('.$metric().') OVER (PARTITION BY '
                .implode(', ', [...$this->dimensions, $this->dialect->timeBucket('year', (string) $this->time)])
                ." ORDER BY {$this->time})",
            'difference' => $metric().' - LAG('.$metric().") {$alongTime}",
            'percent_change' => '('.$metric().' - LAG('.$metric().") {$alongTime}) * 1.0 / NULLIF(ABS(LAG(".$metric().") {$alongTime}), 0)",
            'moving_average' => 'AVG('.$metric().") OVER ({$series}ORDER BY {$this->time} ROWS BETWEEN ".(($calc['window'] ?? 3) - 1).' PRECEDING AND CURRENT ROW)',
            'rank' => 'RANK() '.($this->time !== null ? "OVER (PARTITION BY {$this->time} ORDER BY " : 'OVER (ORDER BY ').$metric().' DESC)',
            default => throw new QueryValidationException("Unsupported quick function '{$fn}'."),
        };

        $label = self::LABELS[$fn].($fn === 'moving_average' ? ' ('.($calc['window'] ?? 3).')' : '');

        return [$sql, [
            'key' => SemanticQuery::calculationKey($calc['metric'], $fn),
            'label' => "{$def['label']} · {$label}",
            'role' => 'metric',
            'type' => 'number',
            'format' => match ($fn) {
                'percent_of_total', 'percent_change' => 'percent',
                'rank' => 'number',
                default => $def['format'],
            },
            'calc' => ['fn' => $fn, 'metric' => $calc['metric']],
        ]];
    }

    private function assertApplicable(string $fn, string $metricKey, string $label): void
    {
        $name = self::LABELS[$fn] ?? $fn;
        if (in_array($fn, self::NEEDS_TIME, true) && $this->time === null) {
            throw new QueryValidationException("{$name} needs a time axis — add a time grain.");
        }
        if ($fn === 'year_to_date' && $this->grain === 'year') {
            throw new QueryValidationException('Year to date needs a grain finer than a year.');
        }
        if ($fn === 'rank' && $this->dimensions === []) {
            throw new QueryValidationException('Rank needs a dimension to rank.');
        }
        if (in_array($fn, self::NEEDS_ADDITIVE, true) && ! $this->catalog->isAdditive($metricKey)) {
            throw new QueryValidationException("{$name} is not meaningful for '{$label}': it is a ratio or average, so its values cannot be added up.");
        }
    }
}
