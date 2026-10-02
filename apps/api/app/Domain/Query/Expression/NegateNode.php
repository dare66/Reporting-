<?php

namespace App\Domain\Query\Expression;

final class NegateNode implements Node
{
    public function __construct(public readonly Node $inner) {}

    public function toSql(callable $measureSql): string
    {
        return '(-'.$this->inner->toSql($measureSql).')';
    }

    public function evaluate(array $values): ?float
    {
        $v = $this->inner->evaluate($values);

        return $v === null ? null : -$v;
    }

    public function references(): array
    {
        return $this->inner->references();
    }
}
