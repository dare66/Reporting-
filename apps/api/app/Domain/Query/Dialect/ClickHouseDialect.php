<?php

namespace App\Domain\Query\Dialect;

use InvalidArgumentException;

/**
 * ClickHouse SQL generation for the production analytical store.
 * Uses -If combinators instead of FILTER clauses.
 */
class ClickHouseDialect implements Dialect
{
    public function name(): string
    {
        return 'clickhouse';
    }

    public function quote(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Unsafe identifier: {$identifier}");
        }

        return '`'.$identifier.'`';
    }

    public function timeBucket(string $grain, string $expression): string
    {
        return match ($grain) {
            'day' => "toDate({$expression})",
            'week' => "toMonday({$expression})",
            'month' => "toStartOfMonth({$expression})",
            'quarter' => "toStartOfQuarter({$expression})",
            'year' => "toStartOfYear({$expression})",
            default => throw new InvalidArgumentException("Unsupported grain {$grain}"),
        };
    }

    public function aggregate(string $aggregation, ?string $expression, ?string $filterSql): string
    {
        $expr = $expression ?? '';
        if ($filterSql === null) {
            return match ($aggregation) {
                'count' => $expression ? "count({$expr})" : 'count()',
                'count_distinct' => "uniqExact({$expr})",
                'sum' => "sum({$expr})",
                'avg' => "avg({$expr})",
                'min' => "min({$expr})",
                'max' => "max({$expr})",
                default => throw new InvalidArgumentException("Unsupported aggregation {$aggregation}"),
            };
        }

        return match ($aggregation) {
            'count' => $expression ? "countIf({$expr}, {$filterSql})" : "countIf({$filterSql})",
            'count_distinct' => "uniqExactIf({$expr}, {$filterSql})",
            'sum' => "sumIf({$expr}, {$filterSql})",
            'avg' => "avgIf({$expr}, {$filterSql})",
            'min' => "minIf({$expr}, {$filterSql})",
            'max' => "maxIf({$expr}, {$filterSql})",
            default => throw new InvalidArgumentException("Unsupported aggregation {$aggregation}"),
        };
    }

    public function caseInsensitiveContains(string $expression): string
    {
        return "positionCaseInsensitive({$expression}, ?) > 0";
    }

    public function containsBinding(string $needle): string
    {
        return $needle;
    }
}
