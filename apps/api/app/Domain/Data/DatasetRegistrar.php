<?php

namespace App\Domain\Data;

use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DataSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Registers physical analytical tables as datasets by introspecting the
 * information schema, then profiles them (row counts, nulls, cardinality,
 * ranges, freshness). Profiling feeds the Data Quality agent and the UI.
 */
class DatasetRegistrar
{
    private const TYPE_MAP = [
        'smallint' => 'integer', 'integer' => 'integer', 'bigint' => 'integer',
        'numeric' => 'decimal', 'double precision' => 'decimal', 'real' => 'decimal',
        'boolean' => 'boolean', 'date' => 'date',
        'timestamp without time zone' => 'timestamp', 'timestamp with time zone' => 'timestamp',
    ];

    /** @param array<string> $sensitive field names classified as sensitive */
    public function register(DataSource $source, string $schema, string $table, ?string $label = null, array $sensitive = [], ?string $description = null): Dataset
    {
        $this->assertIdentifier($schema);
        $this->assertIdentifier($table);

        $columns = DB::connection('analytics')->select(
            'SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
            [$schema, $table],
        );
        if ($columns === []) {
            throw new InvalidArgumentException("Table {$schema}.{$table} was not found or is not readable.");
        }

        $dataset = Dataset::updateOrCreate(
            ['organisation_id' => $source->organisation_id, 'name' => $table],
            [
                'data_source_id' => $source->id,
                'label' => $label ?? Str::headline($table),
                'description' => $description,
                'physical_schema' => $schema,
                'physical_table' => $table,
            ],
        );

        foreach ($columns as $c) {
            DatasetField::updateOrCreate(
                ['dataset_id' => $dataset->id, 'name' => $c->column_name],
                [
                    'label' => Str::headline($c->column_name),
                    'data_type' => self::TYPE_MAP[$c->data_type] ?? 'string',
                    'is_sensitive' => in_array($c->column_name, $sensitive, true),
                ],
            );
        }

        return $this->profile($dataset->fresh('fields'));
    }

    public function profile(Dataset $dataset): Dataset
    {
        $conn = DB::connection('analytics');
        $q = fn (string $id) => '"'.$id.'"';
        $table = $q($dataset->physical_schema).'.'.$q($dataset->physical_table);
        $rowCount = (int) $conn->selectOne("SELECT count(*) AS n FROM {$table}")->n;

        $issues = [];
        $freshness = null;
        foreach ($dataset->fields as $field) {
            $col = $q($field->name);
            $isNumeric = in_array($field->data_type, ['integer', 'decimal'], true);
            $isTemporal = in_array($field->data_type, ['date', 'timestamp'], true);
            $range = ($isNumeric || $isTemporal) ? ", MIN({$col})::text AS min_v, MAX({$col})::text AS max_v" : '';
            $stats = $conn->selectOne("SELECT COUNT(*) FILTER (WHERE {$col} IS NULL) AS nulls, COUNT(DISTINCT {$col}) AS distinct_n{$range} FROM {$table}");

            $profile = [
                'null_count' => (int) $stats->nulls,
                'null_pct' => $rowCount ? round($stats->nulls / $rowCount * 100, 2) : 0,
                'distinct' => (int) $stats->distinct_n,
                'min' => $stats->min_v ?? null,
                'max' => $stats->max_v ?? null,
            ];
            if (! $isNumeric && ! $isTemporal && $stats->distinct_n > 0 && $stats->distinct_n <= 30 && ! $field->is_sensitive) {
                $profile['top_values'] = array_map(fn ($r) => ['value' => $r->v, 'count' => (int) $r->n],
                    $conn->select("SELECT {$col}::text AS v, COUNT(*) AS n FROM {$table} GROUP BY 1 ORDER BY 2 DESC LIMIT 8"));
            }
            if ($isNumeric && $rowCount > 30) {
                $o = $conn->selectOne("SELECT AVG({$col})::float AS mean, STDDEV_POP({$col})::float AS sd FROM {$table}");
                if ($o->sd > 0) {
                    $outliers = (int) $conn->selectOne("SELECT COUNT(*) AS n FROM {$table} WHERE ABS({$col}::float - CAST(? AS float)) > 4 * CAST(? AS float)", [$o->mean, $o->sd])->n;
                    $profile['outliers_4sd'] = $outliers;
                }
            }
            if ($profile['null_pct'] > 20) {
                $issues[] = ['severity' => 'warning', 'field' => $field->name, 'message' => "{$profile['null_pct']}% of values are missing."];
            }
            if ($isTemporal && $profile['max']) {
                $freshness = max($freshness ?? $profile['max'], $profile['max']);
            }
            $field->update(['profile' => $profile]);
        }

        // A key column is `id`, or an `…_id` column that is almost unique: repeats there are duplicates, not references.
        foreach ($dataset->fields as $field) {
            $distinct = $field->profile['distinct'] ?? 0;
            $isKey = $field->name === 'id' || (str_ends_with($field->name, '_id') && $rowCount > 0 && $distinct >= $rowCount * 0.9);
            if ($isKey && $distinct + ($field->profile['null_count'] ?? 0) < $rowCount) {
                $issues[] = ['severity' => 'critical', 'field' => $field->name, 'message' => ($rowCount - $distinct).' duplicate identifiers detected in '.$field->label.'.'];
            }
        }
        $duplicateRows = $rowCount - (int) $conn->selectOne("SELECT COUNT(*) AS n FROM (SELECT DISTINCT * FROM {$table}) d")->n;
        if ($duplicateRows > 0) {
            $issues[] = ['severity' => 'warning', 'field' => null, 'message' => "{$duplicateRows} rows are exact duplicates of another row."];
        }

        $dataset->update([
            'row_count' => $rowCount,
            'freshness_at' => $freshness,
            'profile' => ['profiled_at' => now()->toIso8601String(), 'issues' => $issues, 'column_count' => $dataset->fields->count(), 'duplicate_rows' => $duplicateRows],
        ]);

        return $dataset;
    }

    private function assertIdentifier(string $id): void
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $id)) {
            throw new InvalidArgumentException("Invalid identifier {$id}");
        }
    }
}
