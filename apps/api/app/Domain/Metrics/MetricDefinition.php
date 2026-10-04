<?php

namespace App\Domain\Metrics;

use App\Domain\Query\Expression\ExpressionParser;
use Throwable;

/**
 * What a metric *is*, as a self-contained snapshot: its words (label,
 * description, owners) and its maths (expression, the measures it uses, the
 * table they read). The hash covers only the maths, so renaming a certified
 * metric keeps it certified, while changing how it is computed does not.
 *
 * @phpstan-type MeasureDef array{key: string, aggregation: string, field: string|null, filters: list<array<string, mixed>>}
 */
class MetricDefinition
{
    /** Fields a person edits; changes to these are versioned but do not affect certification. */
    public const DESCRIPTIVE = ['label', 'description', 'format', 'higher_is_better', 'target', 'synonyms', 'is_kpi', 'owner', 'business_owner_id', 'data_owner_id'];

    /**
     * @param  array<string, mixed>  $metric  a metric row (model attributes or a raw row)
     * @param  array<string, MeasureDef>  $measures  the model's measures, keyed by key
     * @return array<string, mixed>
     */
    public function snapshot(array $metric, array $measures, string $base): array
    {
        $decode = fn ($v) => is_string($v) ? (json_decode($v, true) ?? []) : ($v ?? []);
        $snapshot = ['key' => $metric['key'], 'expression' => $this->normalise((string) $metric['expression'])];
        foreach (self::DESCRIPTIVE as $field) {
            $snapshot[$field] = $field === 'synonyms' ? array_values($decode($metric[$field] ?? [])) : ($metric[$field] ?? null);
        }
        $snapshot['higher_is_better'] = (bool) $snapshot['higher_is_better'];
        $snapshot['is_kpi'] = (bool) $snapshot['is_kpi'];
        $snapshot['target'] = $snapshot['target'] === null ? null : (float) $snapshot['target'];
        $used = [];
        foreach ($this->references($snapshot['expression'], array_keys($measures)) as $ref) {
            if (isset($measures[$ref])) {
                $m = $measures[$ref];
                $used[$ref] = ['key' => $ref, 'aggregation' => $m['aggregation'], 'field' => $m['field'], 'filters' => array_values($decode($m['filters']))];
            }
        }
        ksort($used);
        $snapshot['measures'] = array_values($used);
        $snapshot['base'] = $base;

        return $snapshot;
    }

    /** @param  array<string, mixed>  $snapshot */
    public function hash(array $snapshot): string
    {
        return hash('sha256', (string) json_encode([$snapshot['expression'], $snapshot['measures'], $snapshot['base']]));
    }

    /**
     * A readable formula: "COUNT(*) WHERE status = 'Approved' ÷ COUNT(*)".
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function formula(array $snapshot): string
    {
        $out = (string) $snapshot['expression'];
        foreach ($snapshot['measures'] as $m) {
            $sql = strtoupper(str_replace('_', ' ', $m['aggregation'])).'('.($m['field'] ?? '*').')';
            if ($m['filters']) {
                $sql .= ' WHERE '.implode(' AND ', array_map(fn ($f) => "{$f['field']} {$this->op($f['op'])} ".$this->literal($f['value'] ?? null), $m['filters']));
            }
            $out = (string) preg_replace('/\b'.preg_quote($m['key'], '/').'\b/', count($snapshot['measures']) > 1 ? "[{$sql}]" : $sql, $out);
        }

        return str_replace(' / ', ' ÷ ', $out);
    }

    /**
     * @param  list<string>  $measureKeys
     * @return list<string>
     */
    public function references(string $expression, array $measureKeys): array
    {
        try {
            return (new ExpressionParser($measureKeys))->parse($expression)->references();
        } catch (Throwable) {
            // An expression that no longer parses still records what it named.
            preg_match_all('/[a-z_][a-z0-9_]*/', $expression, $m);

            return array_values(array_unique($m[0]));
        }
    }

    private function literal(mixed $value): string
    {
        return match (true) {
            is_string($value) => "'".str_replace("'", "''", $value)."'",
            is_array($value) => '('.implode(', ', array_map($this->literal(...), $value)).')',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            $value === null => 'NULL',
            default => (string) $value,
        };
    }

    private function normalise(string $expression): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $expression));
    }

    private function op(string $op): string
    {
        return ['eq' => '=', 'neq' => '≠', 'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤', 'in' => 'IN', 'not_in' => 'NOT IN'][$op] ?? $op;
    }
}
