<?php

namespace App\Domain\Semantic;

use App\Models\Dataset;
use Illuminate\Support\Str;

/**
 * Proposes a semantic model from a profiled dataset ("AI creates the semantic
 * model"): time dimension from temporal fields, dimensions from low-cardinality
 * text, measures and metrics from numeric fields. Heuristic and transparent —
 * every choice carries a reason the user can review before publishing.
 */
class SemanticModelGenerator
{
    public function propose(Dataset $dataset): array
    {
        $dataset->loadMissing('fields');
        $rows = max(1, (int) $dataset->row_count);
        $dims = [];
        $measures = [['key' => 'record_count', 'label' => 'Records', 'aggregation' => 'count']];
        $metrics = [['key' => 'records', 'label' => Str::headline(Str::plural($dataset->label)), 'expression' => 'record_count', 'format' => 'number', 'is_kpi' => true,
            'synonyms' => ['count', 'volume', 'number of '.Str::lower($dataset->label)], 'reason' => 'Every dataset can be counted.']];
        $time = null;
        $reasons = [];

        foreach ($dataset->fields as $f) {
            $p = $f->profile ?? [];
            // Near-unique integers/strings are identifiers; near-unique decimals are just continuous values.
            $idLike = $f->name === 'id' || str_ends_with($f->name, '_id')
                || (in_array($f->data_type, ['integer', 'string'], true) && $rows > 20 && ($p['distinct'] ?? 0) >= $rows * 0.95);
            if (in_array($f->data_type, ['date', 'timestamp'], true)) {
                $dims[] = ['key' => $f->name, 'label' => $f->label, 'field' => $f->name, 'type' => 'time', 'root_cause' => false];
                $time ??= $f->name;
                $reasons[] = "{$f->label}: temporal field → time dimension";
            } elseif (in_array($f->data_type, ['string', 'boolean'], true) && ! $idLike && ($p['distinct'] ?? 0) <= 500) {
                $dims[] = ['key' => $f->name, 'label' => $f->label, 'field' => $f->name, 'type' => 'string', 'sensitive' => $f->is_sensitive];
                $reasons[] = "{$f->label}: ".($p['distinct'] ?? '?').' distinct values → dimension';
            } elseif (in_array($f->data_type, ['integer', 'decimal'], true) && ! $idLike) {
                $measures[] = ['key' => "{$f->name}_sum", 'label' => "Total {$f->label}", 'aggregation' => 'sum', 'field' => $f->name];
                $measures[] = ['key' => "{$f->name}_avg", 'label' => "Average {$f->label}", 'aggregation' => 'avg', 'field' => $f->name];
                $metrics[] = ['key' => "total_{$f->name}", 'label' => "Total {$f->label}", 'expression' => "{$f->name}_sum", 'format' => $this->guessFormat($f->name), 'is_kpi' => true,
                    'synonyms' => [Str::lower($f->label)], 'reason' => 'Numeric field → additive measure'];
                $metrics[] = ['key' => "avg_{$f->name}", 'label' => "Average {$f->label}", 'expression' => "{$f->name}_avg", 'format' => $this->guessFormat($f->name),
                    'synonyms' => ['average '.Str::lower($f->label)], 'reason' => 'Numeric field → average'];
                $reasons[] = "{$f->label}: numeric → sum and average";
            }
        }

        return [
            'key' => Str::limit(Str::snake(Str::ascii($dataset->label)), 40, ''),
            'name' => $dataset->label,
            'description' => "Generated from {$dataset->label}. Review names and synonyms before publishing.",
            'base' => $dataset->name,
            'time_dimension' => $time,
            'dimensions' => $dims,
            'measures' => $measures,
            'metrics' => $metrics,
            'reasons' => $reasons,
        ];
    }

    private function guessFormat(string $field): string
    {
        return match (true) {
            (bool) preg_match('/amount|revenue|price|cost|fee|sales|value|total/', $field) => 'currency',
            (bool) preg_match('/rate|pct|percent|ratio/', $field) => 'percent',
            (bool) preg_match('/days|duration/', $field) => 'duration_days',
            default => 'number',
        };
    }
}
