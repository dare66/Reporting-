<?php

namespace App\Domain\Semantic;

use App\Domain\Query\Expression\ExpressionParser;
use App\Models\DataLineage;
use App\Models\Dataset;
use App\Models\SemanticModel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates or replaces a semantic model from its portable definition
 * ("semantic model as code"). Validates every reference before writing.
 */
class SemanticModelImporter
{
    /** @param array<string, mixed> $def */
    public function import(string $organisationId, array $def): SemanticModel
    {
        $datasets = Dataset::withoutGlobalScopes()->with('fields')->where('organisation_id', $organisationId)->get()->keyBy('name');
        $ds = fn (string $name) => $datasets[$name] ?? throw new InvalidArgumentException("Dataset '{$name}' does not exist.");
        $assertField = function (string $datasetName, ?string $field) use ($ds) {
            if ($field !== null && ! $ds($datasetName)->fields->contains('name', $field)) {
                throw new InvalidArgumentException("Field '{$datasetName}.{$field}' does not exist.");
            }
        };

        $base = $ds($def['base']);
        foreach ($def['measures'] as $m) {
            $assertField($def['base'], $m['field'] ?? null);
            foreach ($m['filters'] ?? [] as $f) {
                $assertField($def['base'], $f['field']);
            }
        }
        $parser = new ExpressionParser(array_column($def['measures'], 'key'));
        foreach ($def['metrics'] as $m) {
            $parser->parse($m['expression']);
        }

        return DB::transaction(function () use ($organisationId, $def, $base, $ds, $assertField) {
            $model = SemanticModel::withoutGlobalScopes()->firstOrNew(['organisation_id' => $organisationId, 'key' => $def['key']]);
            $isNew = ! $model->exists;
            $model->fill([
                'organisation_id' => $organisationId,
                'base_dataset_id' => $base->id,
                'name' => $def['name'],
                'description' => $def['description'] ?? null,
                'domain' => $def['domain'] ?? null,
                'time_dimension' => $def['time_dimension'] ?? null,
                'status' => 'published',
                'version' => $isNew ? 1 : $model->version + 1,
            ])->save();

            foreach (['dimensions', 'measures', 'metrics', 'relationships', 'hierarchies', 'rowLevelPolicies'] as $rel) {
                $model->{$rel}()->delete();
            }

            foreach ($def['relationships'] ?? [] as $r) {
                [$fromDs, $fromField] = explode('.', $r['from']);
                [$toDs, $toField] = explode('.', $r['to']);
                $assertField($fromDs, $fromField);
                $assertField($toDs, $toField);
                $model->relationships()->create([
                    'from_dataset_id' => $ds($fromDs)->id, 'from_field' => $fromField,
                    'to_dataset_id' => $ds($toDs)->id, 'to_field' => $toField, 'type' => 'many_to_one',
                ]);
            }
            foreach ($def['dimensions'] as $d) {
                $dsName = $d['dataset'] ?? $def['base'];
                $assertField($dsName, $d['field']);
                $model->dimensions()->create([
                    'dataset_id' => $ds($dsName)->id, 'key' => $d['key'], 'label' => $d['label'], 'field' => $d['field'],
                    'type' => $d['type'] ?? 'string', 'description' => $d['description'] ?? null,
                    'is_sensitive' => $d['sensitive'] ?? false, 'root_cause_candidate' => $d['root_cause'] ?? ($d['type'] ?? 'string') === 'string',
                    'synonyms' => $d['synonyms'] ?? [],
                ]);
            }
            foreach ($def['measures'] as $m) {
                $model->measures()->create([
                    'key' => $m['key'], 'label' => $m['label'], 'aggregation' => $m['aggregation'],
                    'field' => $m['field'] ?? null, 'filters' => $m['filters'] ?? [], 'description' => $m['description'] ?? null,
                ]);
            }
            foreach ($def['metrics'] as $m) {
                $model->metrics()->create([
                    'key' => $m['key'], 'label' => $m['label'], 'expression' => $m['expression'],
                    'description' => $m['description'] ?? null, 'format' => $m['format'] ?? 'number',
                    'higher_is_better' => $m['higher_is_better'] ?? true, 'target' => $m['target'] ?? null,
                    'synonyms' => $m['synonyms'] ?? [], 'is_kpi' => $m['is_kpi'] ?? false, 'owner' => $m['owner'] ?? null,
                ]);
            }
            foreach ($def['hierarchies'] ?? [] as $h) {
                $model->hierarchies()->create($h);
            }
            foreach ($def['policies'] ?? [] as $p) {
                $model->rowLevelPolicies()->create([
                    'dimension_key' => $p['dimension'], 'user_attribute' => $p['attribute'], 'exempt_roles' => $p['exempt_roles'] ?? [],
                ]);
            }

            $this->recordLineage($organisationId, $model->fresh(['metrics', 'measures', 'baseDataset']));

            return $model;
        });
    }

    /** Source → table → field → aggregation → metric edges for the lineage graph. */
    private function recordLineage(string $organisationId, SemanticModel $model): void
    {
        DataLineage::withoutGlobalScopes()->where('organisation_id', $organisationId)
            ->where('to_type', 'metric')->where('to_ref', 'like', $model->key.'.%')->delete();

        $parser = new ExpressionParser($model->measures->pluck('key')->all());
        $measures = $model->measures->keyBy('key');
        foreach ($model->metrics as $metric) {
            foreach ($parser->parse($metric->expression)->references() as $ref) {
                $m = $measures[$ref];
                DataLineage::create([
                    'organisation_id' => $organisationId,
                    'from_type' => 'field',
                    'from_ref' => $model->baseDataset->physical_schema.'.'.$model->baseDataset->physical_table.'.'.($m->field ?? '*'),
                    'to_type' => 'metric',
                    'to_ref' => $model->key.'.'.$metric->key,
                    'transformation' => strtoupper($m->aggregation).'('.($m->field ?? '*').')'
                        .($m->filters ? ' WHERE '.collect($m->filters)->map(fn ($f) => $f['field'].' '.$f['op'].' '.json_encode($f['value'] ?? null))->implode(' AND ') : ''),
                ]);
            }
        }
    }
}
