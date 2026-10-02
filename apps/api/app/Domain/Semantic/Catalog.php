<?php

namespace App\Domain\Semantic;

use App\Domain\Query\Expression\ExpressionParser;
use App\Domain\Query\Expression\Node;
use App\Domain\Query\QueryValidationException;
use App\Models\SemanticModel;

/**
 * Immutable, engine-ready view of a semantic model.
 *
 * Built either from the metadata database or from a portable array
 * (the same shape is used for import/export and unit tests).
 */
final class Catalog
{
    /** @var array<string, Node> */
    private array $parsedMetrics = [];

    /**
     * @param  array<string, array{id: string, name: string, label: string, schema: string, table: string, fields: array<string, string>}>  $datasets  keyed by dataset id
     * @param  array<string, array<string, mixed>>  $dimensions  keyed by key
     * @param  array<string, array<string, mixed>>  $measures  keyed by key
     * @param  array<string, array<string, mixed>>  $metrics  keyed by key
     * @param  array<int, array{from_dataset_id: string, from_field: string, to_dataset_id: string, to_field: string}>  $relationships
     * @param  array<int, array{dimension_key: string, user_attribute: string, exempt_roles: array<string>}>  $policies
     * @param  array<int, array{key: string, label: string, levels: array<string>}>  $hierarchies
     */
    public function __construct(
        public readonly string $id,
        public readonly string $key,
        public readonly string $name,
        public readonly string $baseDatasetId,
        public readonly ?string $timeDimension,
        public readonly array $datasets,
        public readonly array $dimensions,
        public readonly array $measures,
        public readonly array $metrics,
        public readonly array $relationships = [],
        public readonly array $policies = [],
        public readonly array $hierarchies = [],
        public readonly ?string $description = null,
    ) {}

    public static function fromModel(SemanticModel $model): self
    {
        $model->loadMissing(['dimensions.dataset.fields', 'measures', 'metrics', 'relationships', 'rowLevelPolicies', 'hierarchies', 'baseDataset.fields']);

        $datasets = [];
        $addDataset = function ($ds) use (&$datasets) {
            if ($ds && ! isset($datasets[$ds->id])) {
                $datasets[$ds->id] = [
                    'id' => $ds->id, 'name' => $ds->name, 'label' => $ds->label,
                    'schema' => $ds->physical_schema, 'table' => $ds->physical_table,
                    'fields' => $ds->fields->pluck('data_type', 'name')->all(),
                ];
            }
        };
        $addDataset($model->baseDataset);
        foreach ($model->dimensions as $d) {
            $addDataset($d->dataset);
        }
        foreach ($model->relationships as $r) {
            foreach ([$r->from_dataset_id, $r->to_dataset_id] as $dsId) {
                if (! isset($datasets[$dsId])) {
                    $addDataset(\App\Models\Dataset::with('fields')->find($dsId));
                }
            }
        }

        return new self(
            id: $model->id,
            key: $model->key,
            name: $model->name,
            baseDatasetId: $model->base_dataset_id,
            timeDimension: $model->time_dimension,
            datasets: $datasets,
            dimensions: $model->dimensions->mapWithKeys(fn ($d) => [$d->key => [
                'key' => $d->key, 'label' => $d->label, 'dataset_id' => $d->dataset_id, 'field' => $d->field,
                'type' => $d->type, 'is_sensitive' => $d->is_sensitive, 'synonyms' => $d->synonyms ?? [],
                'description' => $d->description, 'root_cause_candidate' => $d->root_cause_candidate,
            ]])->all(),
            measures: $model->measures->mapWithKeys(fn ($m) => [$m->key => [
                'key' => $m->key, 'label' => $m->label, 'aggregation' => $m->aggregation,
                'field' => $m->field, 'filters' => $m->filters ?? [], 'description' => $m->description,
            ]])->all(),
            metrics: $model->metrics->mapWithKeys(fn ($m) => [$m->key => [
                'key' => $m->key, 'label' => $m->label, 'expression' => $m->expression, 'format' => $m->format,
                'higher_is_better' => $m->higher_is_better, 'target' => $m->target, 'synonyms' => $m->synonyms ?? [],
                'is_kpi' => $m->is_kpi, 'description' => $m->description, 'owner' => $m->owner,
            ]])->all(),
            relationships: $model->relationships->map(fn ($r) => $r->only(['from_dataset_id', 'from_field', 'to_dataset_id', 'to_field']))->all(),
            policies: $model->rowLevelPolicies->map(fn ($p) => [
                'dimension_key' => $p->dimension_key, 'user_attribute' => $p->user_attribute, 'exempt_roles' => $p->exempt_roles ?? [],
            ])->all(),
            hierarchies: $model->hierarchies->map(fn ($h) => ['key' => $h->key, 'label' => $h->label, 'levels' => $h->levels])->all(),
            description: $model->description,
        );
    }

    public function dimension(string $key): array
    {
        return $this->dimensions[$key] ?? throw new QueryValidationException("Unknown dimension '{$key}'.");
    }

    public function metric(string $key): array
    {
        return $this->metrics[$key] ?? throw new QueryValidationException("Unknown metric '{$key}'.");
    }

    public function measure(string $key): array
    {
        return $this->measures[$key] ?? throw new QueryValidationException("Unknown measure '{$key}'.");
    }

    public function metricAst(string $key): Node
    {
        return $this->parsedMetrics[$key] ??= (new ExpressionParser(array_keys($this->measures)))
            ->parse($this->metric($key)['expression']);
    }

    public function baseDataset(): array
    {
        return $this->datasets[$this->baseDatasetId];
    }

    /** Hierarchy level below the given dimension, used for drill-down. */
    public function childLevel(string $dimensionKey): ?string
    {
        foreach ($this->hierarchies as $h) {
            $i = array_search($dimensionKey, $h['levels'], true);
            if ($i !== false && isset($h['levels'][$i + 1])) {
                return $h['levels'][$i + 1];
            }
        }

        return null;
    }
}
