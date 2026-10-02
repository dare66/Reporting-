<?php

namespace App\Domain\Query\Expression;

use LogicException;

final class BinaryNode implements Node
{
    public function __construct(public readonly string $op, public readonly Node $left, public readonly Node $right) {}

    public function toSql(callable $measureSql): string
    {
        $l = $this->left->toSql($measureSql);
        $r = $this->right->toSql($measureSql);

        // Division is always float division and never divides by zero.
        if ($this->op === '/') {
            return "(CAST({$l} AS DOUBLE PRECISION) / NULLIF({$r}, 0))";
        }

        return "({$l} {$this->op} {$r})";
    }

    public function evaluate(array $values): ?float
    {
        $l = $this->left->evaluate($values);
        $r = $this->right->evaluate($values);
        if ($l === null || $r === null) {
            return null;
        }

        return match ($this->op) {
            '+' => $l + $r,
            '-' => $l - $r,
            '*' => $l * $r,
            '/' => $r == 0.0 ? null : $l / $r,
            default => throw new LogicException("Unknown operator {$this->op}"),
        };
    }

    public function references(): array
    {
        return array_values(array_unique([...$this->left->references(), ...$this->right->references()]));
    }
}
