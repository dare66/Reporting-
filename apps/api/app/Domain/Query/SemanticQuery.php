<?php

namespace App\Domain\Query;

use Carbon\CarbonImmutable;

/**
 * Validated request against a semantic model. This — not SQL — is the
 * contract between the UI, the AI agents and the query engine.
 */
final class SemanticQuery
{
    public const GRAINS = ['day', 'week', 'month', 'quarter', 'year'];

    public const OPERATORS = ['eq', 'neq', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'between', 'contains', 'is_null', 'not_null'];

    /**
     * @param  array<string>  $metrics
     * @param  array<string>  $measures  raw measures (used by analytics services)
     * @param  array<string>  $dimensions
     * @param  array<int, array{dimension: string, op: string, value?: mixed}>  $filters
     * @param  array<int, array{key: string, dir: 'asc'|'desc'}>  $sort
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
            filters: array_map(self::normaliseFilter(...), array_values((array) ($data['filters'] ?? []))),
            grain: $time['grain'] ?? null,
            timeRange: $range,
            sort: array_map(self::normaliseSort(...), array_values((array) ($data['sort'] ?? []))),
            limit: isset($data['limit']) ? (int) $data['limit'] : null,
            measures: array_values(array_unique((array) ($data['measures'] ?? []))),
        );
    }

    /** @return array{dimension: string, op: string, value?: mixed} */
    private static function normaliseFilter(mixed $filter): array
    {
        if (! is_array($filter) || ! is_string($filter['dimension'] ?? null) || ! is_string($filter['op'] ?? null)) {
            throw new QueryValidationException('Each filter needs a dimension and a supported operator.');
        }

        return array_key_exists('value', $filter)
            ? ['dimension' => $filter['dimension'], 'op' => $filter['op'], 'value' => $filter['value']]
            : ['dimension' => $filter['dimension'], 'op' => $filter['op']];
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

    public function withTimeRange(?TimeRange $range): self
    {
        return new self($this->metrics, $this->dimensions, $this->filters, $this->grain, $range, $this->sort, $this->limit, $this->measures);
    }

    /** @param array<int, array{dimension: string, op: string, value?: mixed}> $filters */
    public function withFilters(array $filters): self
    {
        return new self($this->metrics, $this->dimensions, $filters, $this->grain, $this->timeRange, $this->sort, $this->limit, $this->measures);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'metrics' => $this->metrics,
            'measures' => $this->measures ?: null,
            'dimensions' => $this->dimensions,
            'filters' => $this->filters,
            'time' => array_filter(['grain' => $this->grain, 'range' => $this->timeRange?->toArray()]),
            'sort' => $this->sort,
            'limit' => $this->limit,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
