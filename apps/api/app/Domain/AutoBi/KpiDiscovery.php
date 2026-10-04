<?php

namespace App\Domain\AutoBi;

use Illuminate\Support\Str;

/**
 * Proposes the KPIs a dataset can support, each as governed semantic-layer
 * measures and a metric expression, with a readable formula, a confidence and
 * the reason it was proposed. Nothing here is published: a person approves,
 * edits or rejects each proposal first.
 *
 * @phpstan-import-type FieldUnderstanding from DataUnderstanding
 *
 * @phpstan-type Measure array{key: string, label: string, aggregation: string, field?: string, filters?: list<array{field: string, op: string, value: mixed}>}
 * @phpstan-type Candidate array{
 *     key: string, label: string, formula: string, expression: string, measures: list<Measure>, format: string,
 *     higher_is_better: bool, confidence: float, reason: string, kind: string
 * }
 * @phpstan-type Kpi array{
 *     key: string, label: string, formula: string, expression: string, measures: list<Measure>, format: string,
 *     higher_is_better: bool, confidence: float, reason: string, kind: string, recommended: bool
 * }
 */
class KpiDiscovery
{
    private const MAX = 10;

    /**
     * @param  list<FieldUnderstanding>  $fields
     * @return list<Kpi>
     */
    public function discover(string $entity, array $fields): array
    {
        $plural = Str::plural($entity);
        $count = ['key' => 'record_count', 'label' => $plural, 'aggregation' => 'count'];
        $kpis = [[
            'key' => 'total_'.Str::snake($plural), 'label' => $plural, 'kind' => 'volume', 'formula' => "COUNT({$plural})",
            'expression' => 'record_count', 'measures' => [$count], 'format' => 'number', 'higher_is_better' => true, 'confidence' => 0.99,
            'reason' => "Each row is one {$entity}, so counting rows measures volume.",
        ]];

        foreach ($fields as $f) {
            $k = Str::snake($f['field']);
            if ($f['role'] === 'measure' && $f['format'] === 'currency') {
                $kpis[] = ['key' => "total_{$k}", 'label' => 'Total '.$f['concept'], 'kind' => 'money', 'formula' => "SUM({$f['label']})",
                    'expression' => "{$k}_sum", 'measures' => [['key' => "{$k}_sum", 'label' => 'Total '.$f['concept'], 'aggregation' => 'sum', 'field' => $f['field']]],
                    'format' => 'currency', 'higher_is_better' => ! preg_match('/cost|expense|spend|refund|fee_waiver/', $k), 'confidence' => min(0.97, $f['confidence']),
                    'reason' => "{$f['label']} is money, and money adds up across rows."];
            } elseif ($f['role'] === 'measure' && $f['format'] === 'duration_days') {
                $kpis[] = ['key' => "avg_{$k}", 'label' => 'Average '.$f['concept'], 'kind' => 'duration', 'formula' => "AVG({$f['label']})",
                    'expression' => "{$k}_avg", 'measures' => [['key' => "{$k}_avg", 'label' => 'Average '.$f['concept'], 'aggregation' => 'avg', 'field' => $f['field']]],
                    'format' => 'duration_days', 'higher_is_better' => false, 'confidence' => 0.9,
                    'reason' => "{$f['label']} is a duration; its average shows speed, and lower is usually better."];
            } elseif ($f['role'] === 'measure' && $f['format'] === 'percent') {
                $kpis[] = ['key' => "avg_{$k}", 'label' => 'Average '.$f['concept'], 'kind' => 'rate', 'formula' => "AVG({$f['label']})",
                    'expression' => "{$k}_avg", 'measures' => [['key' => "{$k}_avg", 'label' => 'Average '.$f['concept'], 'aggregation' => 'avg', 'field' => $f['field']]],
                    'format' => 'percent', 'higher_is_better' => true, 'confidence' => 0.8,
                    'reason' => "{$f['label']} is already a rate, so it is averaged, never summed."];
            } elseif ($f['role'] === 'measure') {
                $kpis[] = ['key' => "total_{$k}", 'label' => 'Total '.$f['concept'], 'kind' => 'quantity', 'formula' => "SUM({$f['label']})",
                    'expression' => "{$k}_sum", 'measures' => [['key' => "{$k}_sum", 'label' => 'Total '.$f['concept'], 'aggregation' => 'sum', 'field' => $f['field']]],
                    'format' => 'number', 'higher_is_better' => true, 'confidence' => 0.7,
                    'reason' => "{$f['label']} is a numeric quantity; check that adding it up makes sense."];
            } elseif ($f['role'] === 'status') {
                array_push($kpis, ...$this->statusRates($f, $count, $plural));
            } elseif ($f['role'] === 'flag') {
                $kpis[] = ['key' => "{$k}_share", 'label' => $f['concept'].' share', 'kind' => 'rate', 'formula' => "COUNT({$plural} WHERE {$f['label']} = yes) ÷ COUNT({$plural})",
                    'expression' => "{$k}_yes / record_count",
                    'measures' => [$count, ['key' => "{$k}_yes", 'label' => $f['concept'], 'aggregation' => 'count', 'filters' => [['field' => $f['field'], 'op' => 'eq', 'value' => true]]]],
                    'format' => 'percent', 'higher_is_better' => ! preg_match('/risk|late|breach|overdue|error|fail|fraud/', $k), 'confidence' => 0.88,
                    'reason' => "{$f['label']} is yes/no, so the share of yes is a rate."];
            }
        }

        // Keep keys unique and the list short enough to review; the first four lead the dashboard.
        $kpis = array_values(collect($kpis)->unique('key')->sortByDesc(fn ($k) => [$this->weight($k['kind']), $k['confidence']])->take(self::MAX)->all());
        foreach ($kpis as $i => &$k) {
            $k['confidence'] = round($k['confidence'], 2);
            $k['recommended'] = $i < 4 && $k['confidence'] >= 0.75;
        }

        return $kpis;
    }

    /**
     * Rates from a lifecycle status: "approval rate" = approved ÷ all.
     *
     * @param  FieldUnderstanding  $f
     * @param  Measure  $count
     * @return list<Candidate>
     */
    private function statusRates(array $f, array $count, string $plural): array
    {
        $out = [];
        foreach ($f['values'] ?? [] as $value) {
            $good = DataUnderstanding::LIFECYCLE[Str::lower($value)] ?? null;
            if ($good === null) {
                continue;
            }
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii($value))), '_');
            if ($slug === '' || ctype_digit($slug[0])) {
                continue;
            }
            $k = Str::snake($f['field']);
            $out[] = ['key' => "{$slug}_rate", 'label' => Str::headline($value).' rate', 'kind' => 'rate',
                'formula' => "COUNT({$plural} WHERE {$f['label']} = '{$value}') ÷ COUNT({$plural})",
                'expression' => "{$k}_{$slug}_count / record_count",
                'measures' => [$count, ['key' => "{$k}_{$slug}_count", 'label' => Str::headline($value).' '.$plural, 'aggregation' => 'count',
                    'filters' => [['field' => $f['field'], 'op' => 'eq', 'value' => $value]]]],
                'format' => 'percent', 'higher_is_better' => $good, 'confidence' => min(0.96, $f['confidence']),
                'reason' => "{$f['label']} follows a lifecycle, and '{$value}' is ".($good ? 'a successful' : 'an unsuccessful').' outcome.'];
        }

        return array_slice($out, 0, 3);
    }

    private function weight(string $kind): int
    {
        return ['volume' => 5, 'money' => 4, 'rate' => 3, 'duration' => 2, 'quantity' => 1][$kind] ?? 0;
    }
}
