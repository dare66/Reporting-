<?php

namespace App\Domain\Query;

/**
 * @phpstan-type Row array<string, mixed>
 * @phpstan-type Evidence array{query_hash: string, semantic_query: array<string, mixed>, sql: string, executed_at: string, row_count: int}
 */
final class QueryResult
{
    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<Row>  $rows
     * @param  array<string, mixed>  $query  the semantic query that produced the rows
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

    /**
     * Rows excluding the time bucket still in progress (e.g. the current month).
     *
     * @return list<Row>
     */
    public function completeRows(): array
    {
        return $this->partialFrom === null ? $this->rows : array_values(array_filter($this->rows, fn ($r) => ($r['period'] ?? '') < $this->partialFrom));
    }

    public function withPartialFrom(?string $date): self
    {
        return new self($this->columns, $this->rows, $this->sql, $this->queryHash, $this->durationMs, $this->cached, $this->truncated, $this->executedAt, $this->query, $date);
    }

    /** @return Row */
    public function first(): array
    {
        return $this->rows[0] ?? [];
    }

    /**
     * Evidence block attached to any insight or narrative derived from this result.
     *
     * @return Evidence
     */
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

    /** @return array{columns: list<array<string, mixed>>, rows: list<Row>, meta: array<string, mixed>} */
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
