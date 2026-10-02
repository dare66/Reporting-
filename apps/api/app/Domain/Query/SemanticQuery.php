<?php

namespace App\Domain\Query;

use Carbon\CarbonImmutable;

/**
 * Validated request against a semantic model. This — not SQL — is the
 * contract between the UI, the AI agents and the query engine.
 *
 * @phpstan-type Filter array{dimension: string, op: string, value?: mixed}
 * @phpstan-type Having array{metric: string, op: string, value: mixed}
 * @phpstan-type Calculation array{fn: string, metric: string, window?: int}
 */
final class SemanticQuery
{
    public const GRAINS = ['day', 'week', 'month', 'quarter', 'year'];

    public const OPERATORS = [
        'eq', 'neq', 'in', 'not_in',                                  // members
        'gt', 'gte', 'lt', 'lte', 'between', 'not_between',           // numeric
        'contains', 'not_contains', 'starts_with', 'ends_with',       // text
        'is_null', 'not_null',
        ...self::RANKING_OPERATORS,
    ];

    /** Filters on a dimension by a metric's ranking; resolved by QueryService before compiling. */
    public const RANKING_OPERATORS = ['top', 'bottom'];

    /** Operators allowed on aggregated metrics (HAVING). */
    public const HAVING_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'not_between'];

    /** Quick functions, compiled to SQL window functions over the aggregated metric. */
    public const CALCULATIONS = ['percent_of_total', 'running_sum', 'year_to_date', 'difference', 'percent_change', 'moving_average', 'rank'];

    /**
     * @param  array<string>  $metrics
     * @param  array<string>  $dimensions
     * @param  list<Filter>  $filters
     * @param  list<array{key: string, dir: 'asc'|'desc'}>  $sort
     * @param  array<string>  $measures  raw measures (used by analytics services)
     * @param  list<Having>  $having  filters on aggregated metrics
     * @param  list<Calculation>  $calculations
     */
    public function __construct(
        public readonly array $metrics,
        public readonly array $dimensions = [],
        public readonly array $filters = [],
        public readonly ?string $grain = null,
        public readonly ?TimeRange $timeRange = null,
        public readonly array $sort = [],
        public readonly ?int $limit = null,
        public readonly array $measures = [],
        public readonly array $having = [],
        public readonly array $calculations = [],
    ) {
        if ($metrics === [] && $measures === []) {
            throw new QueryValidationException('Select at least one metric.');
        }
        if ($grain !== null && ! in_array($grain, self::GRAINS, true)) {
            throw new QueryValidationException("Unsupported time grain '{$grain}'.");
        }
        foreach ($filters as $f) {
            if (! in_array($f['op'], self::OPERATORS, true)) {
                throw new QueryValidationException("Unsupported filter operator '{$f['op']}'.");
            }
        }
        foreach ($having as $h) {
            if (! in_array($h['op'], self::HAVING_OPERATORS, true)) {
                throw new QueryValidationException("Unsupported measure filter operator '{$h['op']}'.");
            }
        }
        foreach ($calculations as $c) {
            if (! in_array($c['fn'], self::CALCULATIONS, true)) {
                throw new QueryValidationException("Unsupported quick function '{$c['fn']}'.");
            }
            if (! in_array($c['metric'], $metrics, true)) {
                throw new QueryValidationException("Quick function '{$c['fn']}' needs '{$c['metric']}' among the selected metrics.");
            }
        }
        if ($limit !== null && $limit < 1) {
            throw new QueryValidationException('Limit must be positive.');
        }
    }

    /**
     * The trust boundary: untyped input (HTTP, AI plans, stored widgets) is
     * normalised here, so everything downstream can rely on the shapes above.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?CarbonImmutable $now = null): self
    {
        $time = $data['time'] ?? [];
        $range = isset($time['range']) ? TimeRange::resolve($time['range'], $now) : null;

        return new self(
            metrics: array_values(array_unique((array) ($data['metrics'] ?? []))),
            dimensions: array_values(array_unique((array) ($data['dimensions'] ?? []))),
            filters: self::normaliseFilters($data['filters'] ?? []),
            grain: $time['grain'] ?? null,
            timeRange: $range,
            sort: array_map(self::normaliseSort(...), array_values((array) ($data['sort'] ?? []))),
            limit: isset($data['limit']) ? (int) $data['limit'] : null,
            measures: array_values(array_unique((array) ($data['measures'] ?? []))),
            having: array_map(self::normaliseHaving(...), array_values((array) ($data['having'] ?? []))),
            calculations: array_map(self::normaliseCalculation(...), array_values((array) ($data['calculations'] ?? []))),
        );
    }

    /**
     * Normalises filters from any untrusted source (requests, stored dashboards).
     *
     * @return list<Filter>
     */
    public static function normaliseFilters(mixed $filters): array
    {
        return array_map(self::normaliseFilter(...), array_values((array) $filters));
    }

    /** @return Filter */
    private static function normaliseFilter(mixed $filter): array
    {
        if (! is_array($filter) || ! is_string($filter['dimension'] ?? null) || ! is_string($filter['op'] ?? null)) {
            throw new QueryValidationException('Each filter needs a dimension and a supported operator.');
        }
        if (in_array($filter['op'], self::RANKING_OPERATORS, true)) {
            return ['dimension' => $filter['dimension'], 'op' => $filter['op'], 'value' => self::normaliseRanking($filter['value'] ?? null)];
        }

        return array_key_exists('value', $filter)
            ? ['dimension' => $filter['dimension'], 'op' => $filter['op'], 'value' => $filter['value']]
            : ['dimension' => $filter['dimension'], 'op' => $filter['op']];
    }

    /** @return array{n: int, metric: string} */
    private static function normaliseRanking(mixed $value): array
    {
        $n = is_array($value) && is_numeric($value['n'] ?? null) ? (int) $value['n'] : 0;
        if (! is_array($value) || ! is_string($value['metric'] ?? null) || $n < 1 || $n > 1000) {
            throw new QueryValidationException('A ranking filter needs a metric and a count between 1 and 1,000.');
        }

        return ['n' => $n, 'metric' => $value['metric']];
    }

    /** @return Having */
    private static function normaliseHaving(mixed $having): array
    {
        if (! is_array($having) || ! is_string($having['metric'] ?? null) || ! is_string($having['op'] ?? null) || ! array_key_exists('value', $having)) {
            throw new QueryValidationException('Each measure filter needs a metric, an operator and a value.');
        }

        return ['metric' => $having['metric'], 'op' => $having['op'], 'value' => $having['value']];
    }

    /** @return Calculation */
    private static function normaliseCalculation(mixed $calc): array
    {
        if (! is_array($calc) || ! is_string($calc['fn'] ?? null) || ! is_string($calc['metric'] ?? null)) {
            throw new QueryValidationException('Each quick function needs a function name and a metric.');
        }
        if ($calc['fn'] !== 'moving_average') {
            return ['fn' => $calc['fn'], 'metric' => $calc['metric']];
        }
        $window = (int) ($calc['window'] ?? 3);
        if ($window < 2 || $window > 24) {
            throw new QueryValidationException('A moving average window must be between 2 and 24 periods.');
        }

        return ['fn' => $calc['fn'], 'metric' => $calc['metric'], 'window' => $window];
    }

    /** @return array{key: string, dir: 'asc'|'desc'} */
    private static function normaliseSort(mixed $sort): array
    {
        $dir = is_array($sort) ? strtolower((string) ($sort['dir'] ?? 'asc')) : null;
        if (! is_array($sort) || ! is_string($sort['key'] ?? null) || ($dir !== 'asc' && $dir !== 'desc')) {
            throw new QueryValidationException('Invalid sort specification.');
        }

        return ['key' => $sort['key'], 'dir' => $dir];
    }

    /** Result column key of a quick function. */
    public static function calculationKey(string $metric, string $fn): string
    {
        return "{$metric}__{$fn}";
    }

    public function withTimeRange(?TimeRange $range): self
    {
        return new self($this->metrics, $this->dimensions, $this->filters, $this->grain, $range, $this->sort, $this->limit, $this->measures, $this->having, $this->calculations);
    }

    /** @param list<Filter> $filters */
    public function withFilters(array $filters): self
    {
        return new self($this->metrics, $this->dimensions, $filters, $this->grain, $this->timeRange, $this->sort, $this->limit, $this->measures, $this->having, $this->calculations);
    }

    /** @return list<Filter> */
    public function rankingFilters(): array
    {
        return array_values(array_filter($this->filters, fn ($f) => in_array($f['op'], self::RANKING_OPERATORS, true)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'metrics' => $this->metrics,
            'measures' => $this->measures ?: null,
            'dimensions' => $this->dimensions,
            'filters' => $this->filters,
            'having' => $this->having,
            'calculations' => $this->calculations,
            'time' => array_filter(['grain' => $this->grain, 'range' => $this->timeRange?->toArray()]),
            'sort' => $this->sort,
            'limit' => $this->limit,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
