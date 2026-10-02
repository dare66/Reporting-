<?php

namespace App\Domain\Analytics;

use App\Domain\Query\QueryValidationException;

/** Fully-qualified metric reference: "<model>.<metric>". */
final class MetricRef
{
    public function __construct(public readonly string $model, public readonly string $metric) {}

    public static function parse(string $ref): self
    {
        if (! preg_match('/^([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)$/', $ref, $m)) {
            throw new QueryValidationException("Metric reference '{$ref}' must look like model.metric.");
        }

        return new self($m[1], $m[2]);
    }

    public function __toString(): string
    {
        return $this->model.'.'.$this->metric;
    }
}
