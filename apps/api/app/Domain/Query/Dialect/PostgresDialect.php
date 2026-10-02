<?php

namespace App\Domain\Query\Dialect;

use InvalidArgumentException;

class PostgresDialect implements Dialect
{
    public function name(): string
    {
        return 'postgres';
    }

    public function quote(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Unsafe identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }

    public function timeBucket(string $grain, string $expression): string
    {
        return "CAST(date_trunc('{$grain}', {$expression}) AS DATE)";
    }

    public function aggregate(string $aggregation, ?string $expression, ?string $filterSql): string
    {
        $expr = $expression ?? '*';
        $core = match ($aggregation) {
            'count' => "COUNT({$expr})",
            'count_distinct' => "COUNT(DISTINCT {$expr})",
            'sum' => "SUM({$expr})",
            'avg' => "AVG({$expr})",
            'min' => "MIN({$expr})",
            'max' => "MAX({$expr})",
            default => throw new InvalidArgumentException("Unsupported aggregation {$aggregation}"),
        };
        if ($filterSql !== null) {
            $core .= " FILTER (WHERE {$filterSql})";
        }

        // SUM over zero rows is NULL; for additive measures zero is the truthful answer.
        return in_array($aggregation, ['sum', 'count', 'count_distinct'], true) ? "COALESCE({$core}, 0)" : $core;
    }

    public function caseInsensitiveLike(string $expression): string
    {
        return "{$expression} ILIKE ?";
    }

    public function likePattern(string $needle, string $mode): string
    {
        return LikePattern::for($needle, $mode);
    }
}
