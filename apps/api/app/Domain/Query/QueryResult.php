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
    ) {}

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
            ],
        ];
    }
}
