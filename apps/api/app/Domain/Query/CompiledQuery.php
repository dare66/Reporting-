<?php

namespace App\Domain\Query;

final class CompiledQuery
{
    /**
     * @param  array<int, mixed>  $bindings
     * @param  array<int, array{key: string, label: string, role: string, type: string, format?: string}>  $columns
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        public readonly array $columns,
        public readonly int $limit,
    ) {}

    public function hash(): string
    {
        return hash('sha256', $this->sql.'|'.json_encode($this->bindings));
    }
}
