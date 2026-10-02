<?php

namespace App\Domain\Query\Expression;

final class MeasureNode implements Node
{
    public function __construct(public readonly string $key) {}

    public function toSql(callable $measureSql): string
    {
        return $measureSql($this->key);
    }

    public function evaluate(array $values): ?float
    {
        $v = $values[$this->key] ?? null;

        return $v === null ? null : (float) $v;
    }

    public function references(): array
    {
        return [$this->key];
    }
}
