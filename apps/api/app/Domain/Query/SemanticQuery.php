<?php

namespace App\Domain\Query;

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
     * @param  array<int, array{key: string, dir: string}>  $sort
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
            if (! isset($f['dimension'], $f['op']) || ! in_array($f['op'], self::OPERATORS, true)) {
                throw new QueryValidationException('Each filter needs a dimension and a supported operator.');
            }
        }
        foreach ($sort as $s) {
            if (! isset($s['key']) || ! in_array(strtolower($s['dir'] ?? 'asc'), ['asc', 'desc'], true)) {
                throw new QueryValidationException('Invalid sort specification.');
            }
        }
        if ($limit !== null && $limit < 1) {
            throw new QueryValidationException('Limit must be positive.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?\Carbon\CarbonImmutable $now = null): self
    {
        $time = $data['time'] ?? [];
        $range = isset($time['range']) ? TimeRange::resolve($time['range'], $now) : null;

        return new self(
            metrics: array_values(array_unique((array) ($data['metrics'] ?? []))),
            dimensions: array_values(array_unique((array) ($data['dimensions'] ?? []))),
            filters: array_values((array) ($data['filters'] ?? [])),
            grain: $time['grain'] ?? null,
            timeRange: $range,
            sort: array_values((array) ($data['sort'] ?? [])),
            limit: isset($data['limit']) ? (int) $data['limit'] : null,
            measures: array_values(array_unique((array) ($data['measures'] ?? []))),
        );
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
