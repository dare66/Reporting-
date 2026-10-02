<?php

namespace App\Domain\Query\Expression;

/**
 * AST node for metric expressions. Expressions reference measure keys only, so
 * they compile to SQL without ever interpolating user text, and they can also be
 * evaluated in PHP (used by root-cause decomposition on pre-aggregated measures).
 */
interface Node
{
    /** @param callable(string): string $measureSql */
    public function toSql(callable $measureSql): string;

    /** @param array<string, float|int|null> $values */
    public function evaluate(array $values): ?float;

    /** @return array<string> */
    public function references(): array;
}
