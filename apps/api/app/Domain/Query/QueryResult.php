<?php

namespace App\Domain\Query;

final class QueryResult
{
    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly string $sql,
        public readonly string $queryHash,
        public readonly int $durationMs,
        public readonly bool $cached,
        public readonly bool $truncated,
        public readonly string $executedAt,
        public readonly array $query = [],
        public readonly ?string $partialFrom = null,
    ) {}

    /** Rows whose time bucket is still in progress (e.g. the current month). */
    public function completeRows(): array
    {
        return $this->partialFrom === null ? $this->rows : array_values(array_filter($this->rows, fn ($r) => ($r['period'] ?? '') < $this->partialFrom));
    }

    public function withPartialFrom(?string $date): self
    {
        return new self($this->columns, $this->rows, $this->sql, $this->queryHash, $this->durationMs, $this->cached, $this->truncated, $this->executedAt, $this->query, $date);
    }

    public function first(): array
    {
        return $this->rows[0] ?? [];
    }

    /** Evidence block attached to any insight or narrative derived from this result. */
    public function evidence(): array
    {
        return [
            'query_hash' => $this->queryHash,
            'semantic_query' => $this->query,
            'sql' => $this->sql,
            'executed_at' => $this->executedAt,
            'row_count' => count($this->rows),
        ];
    }

    public function toArray(): array
    {
        return [
            'columns' => $this->columns,
            'rows' => $this->rows,
            'meta' => [
                'query_hash' => $this->queryHash,
                'sql' => $this->sql,
                'duration_ms' => $this->durationMs,
                'cached' => $this->cached,
                'truncated' => $this->truncated,
                'row_count' => count($this->rows),
                'executed_at' => $this->executedAt,
                'query' => $this->query,
                'partial_from' => $this->partialFrom,
            ],
        ];
    }
}
