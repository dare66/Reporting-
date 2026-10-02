<?php

namespace App\Domain\Query\Dialect;

/** SQL generation differences between analytical engines. */
interface Dialect
{
    public function name(): string;

    public function quote(string $identifier): string;

    /**
     * @param  string  $grain  day|week|month|quarter|year; anything else throws
     */
    public function timeBucket(string $grain, string $expression): string;

    /**
     * @param  string  $aggregation  count|count_distinct|sum|avg|min|max; anything else throws
     * @param  string|null  $filterSql  boolean SQL restricting the rows aggregated
     */
    public function aggregate(string $aggregation, ?string $expression, ?string $filterSql): string;

    public function caseInsensitiveContains(string $expression): string;

    /** Binding value paired with caseInsensitiveContains(). */
    public function containsBinding(string $needle): string;
}
