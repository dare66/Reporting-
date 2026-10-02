<?php

namespace App\Domain\Query\Expression;

final class NumberNode implements Node
{
    public function __construct(public readonly float $value) {}

    public function toSql(callable $measureSql): string
    {
        return rtrim(rtrim(number_format($this->value, 10, '.', ''), '0'), '.') ?: '0';
    }

    public function evaluate(array $values): float
    {
        return $this->value;
    }

    public function references(): array
    {
        return [];
    }
}
