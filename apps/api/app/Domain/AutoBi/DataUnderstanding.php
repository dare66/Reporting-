<?php

namespace App\Domain\AutoBi;

use App\Models\Dataset;
use App\Models\DatasetField;
use Illuminate\Support\Str;

/**
 * Works out what each column of a profiled dataset means: its analytical role
 * (identifier, time, measure, dimension…), the business concept it names, and
 * how sure we are. Deterministic and explainable: every conclusion lists the
 * evidence behind it, so a person can check it before anything is published.
 *
 * @phpstan-type FieldUnderstanding array{
 *     field: string, label: string, data_type: string, role: string, concept: string,
 *     format: string|null, confidence: float, reasons: list<string>, values?: list<string>
 * }
 */
class DataUnderstanding
{
    /** Status vocabulary: value → whether reaching it is good (true), bad (false) or neutral (null). */
    public const LIFECYCLE = [
        'approved' => true, 'accepted' => true, 'completed' => true, 'complete' => true, 'success' => true, 'successful' => true,
        'paid' => true, 'won' => true, 'resolved' => true, 'delivered' => true, 'issued' => true, 'enrolled' => true, 'passed' => true, 'active' => true,
        'rejected' => false, 'declined' => false, 'failed' => false, 'failure' => false, 'cancelled' => false, 'canceled' => false,
        'lost' => false, 'overdue' => false, 'refunded' => false, 'withdrawn' => false, 'expired' => false, 'churned' => false,
        'pending' => null, 'open' => null, 'in progress' => null, 'submitted' => null, 'new' => null, 'processing' => null, 'under review' => null,
    ];

    private const GEOGRAPHY = '/(^|_)(country|nationality|nation|region|state|province|city|town|district|territory|market|continent)(_|$)/';

    private const STATUS = '/(^|_)(status|stage|outcome|result|decision|state)(_|$)/';

    private const CURRENCY = '/(amount|revenue|price|cost|fee|fees|sales|payment|income|spend|salary|profit|margin_value|value|total)/';

    private const PERCENT = '/(rate|pct|percent|percentage|ratio|share)/';

    private const DURATION = '/(days|hours|minutes|duration|lead_time|turnaround|tat|age_days|processing_time)/';

    private const IDENTIFIER = '/(^id$|_id$|^id_|_no$|_number$|_ref$|^ref_|uuid|_key$)/';

    /** Domains we can recognise from column and table names, with the words that point to them. */
    private const DOMAINS = [
        'student applications' => ['student', 'application', 'applicant', 'institution', 'university', 'college', 'course', 'programme', 'program', 'visa', 'intake', 'enrolment', 'enrollment'],
        'sales' => ['customer', 'order', 'product', 'sales', 'invoice', 'quantity', 'units', 'discount', 'sku', 'channel', 'deal', 'pipeline'],
        'finance' => ['revenue', 'cost', 'expense', 'budget', 'payment', 'invoice', 'account', 'ledger', 'profit', 'margin', 'tax'],
        'human resources' => ['employee', 'staff', 'department', 'salary', 'hire', 'hired', 'attrition', 'headcount', 'tenure', 'leave', 'grade'],
        'operations' => ['ticket', 'incident', 'sla', 'queue', 'backlog', 'case', 'request', 'resolution', 'priority', 'agent', 'processing'],
    ];

    /**
     * @return array{fields: list<FieldUnderstanding>, entity: string, domain: array{name: string, confidence: float, evidence: list<string>}}
     */
    public function understand(Dataset $dataset): array
    {
        $dataset->loadMissing('fields');
        $rows = max(1, (int) $dataset->row_count);
        $fields = $dataset->fields->map(fn (DatasetField $f) => $this->field($f, $rows))->values()->all();

        return ['fields' => $fields, 'entity' => $this->entity($dataset, $fields), 'domain' => $this->domain($dataset, $fields)];
    }

    /** @return FieldUnderstanding */
    private function field(DatasetField $f, int $rows): array
    {
        $p = $f->profile ?? [];
        $name = Str::lower($f->name);
        $distinct = (int) ($p['distinct'] ?? 0);
        $nonNull = max(1, $rows - (int) ($p['null_count'] ?? 0));
        $uniqueness = $distinct / $nonNull;
        $numeric = in_array($f->data_type, ['integer', 'decimal'], true);
        $values = array_map(fn ($v) => (string) $v['value'], $p['top_values'] ?? []);
        $base = ['field' => $f->name, 'label' => $f->label, 'data_type' => $f->data_type, 'format' => null];

        if ($distinct <= 1) {
            return array_merge($base, ['role' => 'ignored', 'concept' => $f->label, 'confidence' => 0.99,
                'reasons' => [$distinct === 0 ? 'Every value is empty.' : 'Every row holds the same value, so it cannot explain any difference.']]);
        }
        if (in_array($f->data_type, ['date', 'timestamp'], true)) {
            $reasons = ['Stored as a '.($f->data_type === 'date' ? 'date' : 'date and time').'.'];
            if (! empty($p['min']) && ! empty($p['max'])) {
                $reasons[] = 'Covers '.substr((string) $p['min'], 0, 10).' to '.substr((string) $p['max'], 0, 10).'.';
            }

            return array_merge($base, ['role' => 'time', 'concept' => $this->concept($f->label), 'confidence' => 0.99, 'reasons' => $reasons]);
        }

        $nameSaysId = (bool) preg_match(self::IDENTIFIER, $name);
        if ($nameSaysId || (! $numeric && $f->data_type !== 'boolean' && $rows > 20 && $uniqueness >= 0.98) || ($f->data_type === 'integer' && $rows > 20 && $uniqueness >= 0.999 && ! preg_match(self::CURRENCY, $name))) {
            $reasons = [];
            $confidence = 0.55;
            if ($nameSaysId) {
                $reasons[] = 'The name marks it as an identifier.';
                $confidence += 0.3;
            }
            if ($uniqueness >= 0.98) {
                $reasons[] = sprintf('%.1f%% of values are unique.', $uniqueness * 100);
                $confidence += 0.14;
            } else {
                $reasons[] = 'Values repeat, so it refers to another record rather than identifying this one.';
            }

            return array_merge($base, ['role' => 'identifier', 'concept' => $this->entityFromId($f->name), 'confidence' => min(0.99, $confidence), 'reasons' => $reasons]);
        }

        if ($f->data_type === 'boolean') {
            return array_merge($base, ['role' => 'flag', 'concept' => $this->concept($f->label), 'format' => 'percent', 'confidence' => 0.95,
                'reasons' => ['A yes/no field: its share of "yes" is a natural rate.']]);
        }

        if ($numeric) {
            [$role, $format, $why] = match (true) {
                (bool) preg_match(self::PERCENT, $name) => ['measure', 'percent', 'The name describes a rate or share.'],
                (bool) preg_match(self::DURATION, $name) => ['measure', 'duration_days', 'The name describes a duration.'],
                (bool) preg_match(self::CURRENCY, $name) => ['measure', 'currency', 'The name describes money.'],
                default => ['measure', 'number', 'A numeric quantity.'],
            };
            $reasons = [$why];
            $confidence = $format === 'number' ? 0.8 : 0.93;
            if ($f->data_type === 'integer' && $distinct <= 12 && $format === 'number') {
                // Small integer codes (ratings, levels) read better as categories than as sums.
                return array_merge($base, ['role' => 'dimension', 'concept' => $this->concept($f->label), 'confidence' => 0.7,
                    'reasons' => ["Only {$distinct} distinct whole numbers, which reads like a code or level rather than a quantity."], 'values' => $values]);
            }
            if (isset($p['min']) && (float) $p['min'] < 0 && $format === 'currency') {
                $reasons[] = 'Has negative values, so totals net them off.';
            }

            return array_merge($base, ['role' => 'measure', 'concept' => $this->concept($f->label), 'format' => $format, 'confidence' => $confidence, 'reasons' => $reasons]);
        }

        // Text from here on.
        if (preg_match(self::GEOGRAPHY, $name)) {
            return array_merge($base, ['role' => 'geography', 'concept' => $this->concept($f->label), 'confidence' => 0.95,
                'reasons' => ['The name describes a place.', "{$distinct} distinct places."], 'values' => $values]);
        }
        $lifecycle = array_values(array_filter($values, fn ($v) => array_key_exists(Str::lower($v), self::LIFECYCLE)));
        if ($distinct <= 20 && (preg_match(self::STATUS, $name) || count($lifecycle) >= 2)) {
            $reasons = [];
            if (preg_match(self::STATUS, $name)) {
                $reasons[] = 'The name describes a status or stage.';
            }
            if ($lifecycle) {
                $reasons[] = 'Values follow a lifecycle: '.implode(', ', array_slice($lifecycle, 0, 4)).'.';
            }

            return array_merge($base, ['role' => 'status', 'concept' => $this->concept($f->label), 'confidence' => min(0.98, 0.7 + 0.12 * count($reasons) + 0.03 * count($lifecycle)),
                'reasons' => $reasons, 'values' => $values]);
        }
        if ($distinct <= 500) {
            return array_merge($base, ['role' => 'dimension', 'concept' => $this->concept($f->label), 'confidence' => $distinct <= 50 ? 0.9 : 0.75,
                'reasons' => ["{$distinct} distinct values, few enough to group and compare by."], 'values' => $values]);
        }

        return array_merge($base, ['role' => 'text', 'concept' => $this->concept($f->label), 'confidence' => 0.8,
            'reasons' => ["{$distinct} distinct values: too many to group by, so it is kept for look-up only."]]);
    }

    private function concept(string $label): string
    {
        return Str::headline(preg_replace('/\b(Id|Code|Flag|Is)\b/', '', $label) ?: $label);
    }

    private function entityFromId(string $field): string
    {
        $stem = preg_replace(self::IDENTIFIER, '', Str::lower($field));

        return $stem === '' || $stem === null ? 'Record' : Str::headline(Str::singular($stem));
    }

    /** @param list<FieldUnderstanding> $fields */
    private function entity(Dataset $dataset, array $fields): string
    {
        // The most unique identifier names what one row is; otherwise the dataset name does.
        // Only an identifier that is both named as one and unique reaches 0.98.
        $ids = array_filter($fields, fn ($f) => $f['role'] === 'identifier' && $f['confidence'] >= 0.98);
        if ($ids) {
            return array_values($ids)[0]['concept'];
        }
        $words = explode(' ', Str::headline($dataset->label));

        return Str::singular(end($words) ?: 'Record');
    }

    /**
     * @param  list<FieldUnderstanding>  $fields
     * @return array{name: string, confidence: float, evidence: list<string>}
     */
    private function domain(Dataset $dataset, array $fields): array
    {
        $tokens = collect([$dataset->label, ...array_column($fields, 'field')])
            ->flatMap(fn ($s) => preg_split('/[^a-z]+/', Str::lower(Str::snake((string) $s))) ?: [])
            ->map(fn ($t) => Str::singular($t))->filter()->unique()->values()->all();
        $best = ['name' => 'general business data', 'confidence' => 0.3, 'evidence' => []];
        foreach (self::DOMAINS as $domain => $words) {
            $hits = array_values(array_intersect(array_map(fn ($w) => Str::singular($w), $words), $tokens));
            $confidence = $hits ? min(0.97, 0.45 + 0.13 * count($hits)) : 0;
            if ($confidence > $best['confidence']) {
                $best = ['name' => $domain, 'confidence' => round($confidence, 2), 'evidence' => array_map(fn ($h) => "Mentions “{$h}”.", $hits)];
            }
        }

        return $best;
    }
}
