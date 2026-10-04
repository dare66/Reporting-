<?php

namespace App\Domain\Semantic;

use App\Domain\Query\Expression\BinaryNode;
use App\Domain\Query\Expression\ExpressionParser;
use App\Domain\Query\Expression\MeasureNode;
use App\Domain\Query\Expression\NegateNode;
use App\Domain\Query\Expression\Node;
use App\Domain\Query\QueryValidationException;
use App\Models\Dataset;
use App\Models\SemanticModel;

/**
 * Immutable, engine-ready view of a semantic model.
 *
 * Built either from the metadata database or from a portable array
 * (the same shape is used for import/export and unit tests).
 *
 * @phpstan-type DatasetDef array{id: string, name: string, label: string, schema: string, table: string, fields: array<string, string>}
 * @phpstan-type DimensionDef array{key: string, label: string, dataset_id: string, field: string, type: string, is_sensitive: bool, synonyms: list<string>, description: ?string, root_cause_candidate: bool}
 * @phpstan-type MeasureDef array{key: string, label: string, aggregation: string, field: ?string, filters: list<array<string, mixed>>, description: ?string}
 * @phpstan-type MetricDef array{key: string, label: string, expression: string, format: string, higher_is_better: bool, target: ?float, synonyms: list<string>, is_kpi: bool, description: ?string, owner: ?string, status: string}
 */
final class Catalog
{
    /** @var array<string, Node> */
    private array $parsedMetrics = [];

    /**
     * @param  array<string, DatasetDef>  $datasets  keyed by dataset id
     * @param  array<string, DimensionDef>  $dimensions  keyed by key
     * @param  array<string, MeasureDef>  $measures  keyed by key
     * @param  array<string, MetricDef>  $metrics  keyed by key
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
                    $addDataset(Dataset::with('fields')->find($dsId));
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
                'is_kpi' => $m->is_kpi, 'description' => $m->description, 'owner' => $m->owner, 'status' => $m->status,
            ]])->all(),
            relationships: $model->relationships->map(fn ($r) => $r->only(['from_dataset_id', 'from_field', 'to_dataset_id', 'to_field']))->all(),
            policies: $model->rowLevelPolicies->map(fn ($p) => [
                'dimension_key' => $p->dimension_key, 'user_attribute' => $p->user_attribute, 'exempt_roles' => $p->exempt_roles ?? [],
            ])->all(),
            hierarchies: $model->hierarchies->map(fn ($h) => ['key' => $h->key, 'label' => $h->label, 'levels' => $h->levels])->all(),
            description: $model->description,
        );
    }

    /** @return DimensionDef */
    public function dimension(string $key): array
    {
        return $this->dimensions[$key] ?? throw new QueryValidationException("Unknown dimension '{$key}'.");
    }

    /** @return MetricDef */
    public function metric(string $key): array
    {
        return $this->metrics[$key] ?? throw new QueryValidationException("Unknown metric '{$key}'.");
    }

    /** @return MeasureDef */
    public function measure(string $key): array
    {
        return $this->measures[$key] ?? throw new QueryValidationException("Unknown measure '{$key}'.");
    }

    public function metricAst(string $key): Node
    {
        return $this->parsedMetrics[$key] ??= (new ExpressionParser(array_keys($this->measures)))
            ->parse($this->metric($key)['expression']);
    }

    /**
     * True when the metric can be summed across rows: built only from sum/count
     * measures combined by + and −. Shares and running totals are only
     * meaningful for these; ratios and averages are not additive.
     */
    public function isAdditive(string $metricKey): bool
    {
        return $this->additive($this->metricAst($metricKey));
    }

    private function additive(Node $node): bool
    {
        return match (true) {
            $node instanceof MeasureNode => in_array($this->measure($node->key)['aggregation'], ['sum', 'count'], true),
            $node instanceof BinaryNode => in_array($node->op, ['+', '-'], true) && $this->additive($node->left) && $this->additive($node->right),
            $node instanceof NegateNode => $this->additive($node->inner),
            default => false,
        };
    }

    /** @return DatasetDef */
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
