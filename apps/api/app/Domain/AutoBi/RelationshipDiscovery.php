<?php

namespace App\Domain\AutoBi;

use App\Models\Dataset;
use App\Models\DatasetField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds how tables join: a column in one table whose values are the unique
 * keys of another ("applications.student_id → students.student_id"). A name
 * match only nominates a pair; the values decide. A relationship is proposed
 * only when nearly every referencing value exists in the referenced key.
 *
 * @phpstan-type Relationship array{
 *     from: string, to: string, from_dataset: string, to_dataset: string, from_label: string, to_label: string,
 *     cardinality: string, coverage: float, confidence: float, reasons: list<string>
 * }
 */
class RelationshipDiscovery
{
    private const MIN_COVERAGE = 0.9;

    /**
     * @param  iterable<Dataset>  $datasets  profiled, with fields loaded
     * @return list<Relationship>
     */
    public function discover(iterable $datasets): array
    {
        $datasets = collect($datasets)->values();
        $found = [];
        foreach ($datasets as $from) {
            foreach ($datasets as $to) {
                if ($from->id === $to->id) {
                    continue;
                }
                foreach ($this->keys($to) as $key) {
                    foreach ($from->fields as $ref) {
                        if (! $this->namesMatch($ref, $key, $to) || ($ref->name === $key->name && $this->isKey($ref, $from) && $from->row_count >= $to->row_count)) {
                            continue;
                        }
                        if ($rel = $this->verify($from, $ref, $to, $key)) {
                            $found[] = $rel;
                        }
                    }
                }
            }
        }

        return $found;
    }

    /** @return list<DatasetField> columns whose values identify one row of the table */
    private function keys(Dataset $ds): array
    {
        return $ds->fields->filter(fn (DatasetField $f) => $this->isKey($f, $ds) && in_array($f->data_type, ['integer', 'string'], true)
            && ($f->name === 'id' || preg_match('/(_id|_no|_number|_code|_key)$/', $f->name)))->values()->all();
    }

    private function isKey(DatasetField $f, Dataset $ds): bool
    {
        $p = $f->profile ?? [];

        return $ds->row_count > 0 && ($p['null_count'] ?? 1) === 0 && ($p['distinct'] ?? 0) === (int) $ds->row_count;
    }

    private function namesMatch(DatasetField $ref, DatasetField $key, Dataset $to): bool
    {
        // A plain "id" says nothing about what it refers to; only "{entity}_id" can point at another table's id.
        if ($ref->name === $key->name) {
            return $key->name !== 'id';
        }
        // students.id ← applications.student_id
        $words = explode('_', Str::snake(preg_replace('/^ds_[a-z0-9]+_/', '', $to->name) ?? ''));
        $entity = Str::singular((string) end($words));

        return $key->name === 'id' && $ref->name === "{$entity}_id";
    }

    /** @return Relationship|null */
    private function verify(Dataset $from, DatasetField $ref, Dataset $to, DatasetField $key): ?array
    {
        $q = fn (string $id) => '"'.$id.'"';
        $a = $q($from->physical_schema).'.'.$q($from->physical_table);
        $b = $q($to->physical_schema).'.'.$q($to->physical_table);
        $stats = DB::connection('analytics')->selectOne(
            "SELECT COUNT(*) AS n, COUNT(*) FILTER (WHERE EXISTS (SELECT 1 FROM {$b} b WHERE b.{$q($key->name)}::text = r.v)) AS hit
             FROM (SELECT DISTINCT {$q($ref->name)}::text AS v FROM {$a} WHERE {$q($ref->name)} IS NOT NULL) r",
        );
        if ((int) $stats->n === 0) {
            return null;
        }
        $coverage = $stats->hit / $stats->n;
        if ($coverage < self::MIN_COVERAGE) {
            return null;
        }
        $oneToOne = $this->isKey($ref, $from);

        return [
            'from' => "{$from->name}.{$ref->name}", 'to' => "{$to->name}.{$key->name}",
            'from_dataset' => $from->name, 'to_dataset' => $to->name, 'from_label' => "{$from->label} → {$ref->label}", 'to_label' => "{$to->label} → {$key->label}",
            'cardinality' => $oneToOne ? 'one_to_one' : 'many_to_one',
            'coverage' => round($coverage, 4),
            'confidence' => round(min(0.99, 0.5 + 0.45 * $coverage + ($ref->name === $key->name ? 0.04 : 0)), 2),
            'reasons' => [
                $ref->name === $key->name ? "Both tables have a column named {$ref->name}." : "{$ref->label} is named after {$to->label}.",
                "{$key->label} is unique in {$to->label}, so it identifies one row there.",
                sprintf('%.1f%% of the %s values in %s are found in %s.', $coverage * 100, $ref->label, $from->label, $to->label),
            ],
        ];
    }
}
