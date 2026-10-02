<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Domain\Semantic\Catalog;
use App\Domain\Semantic\CatalogRepository;
use App\Domain\Semantic\SemanticModelImporter;
use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Models\DataLineage;
use App\Models\Report;
use App\Models\SemanticModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SemanticModelController extends Controller
{
    public function __construct(private readonly CatalogRepository $catalogs, private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $models = SemanticModel::withCount(['metrics', 'dimensions', 'measures'])->with('baseDataset:id,label,row_count,freshness_at')->orderBy('name')->get();

        return response()->json(['data' => $models->map(fn ($m) => [
            'id' => $m->id, 'key' => $m->key, 'name' => $m->name, 'description' => $m->description, 'domain' => $m->domain,
            'version' => $m->version, 'status' => $m->status, 'time_dimension' => $m->time_dimension,
            'metrics_count' => $m->metrics_count, 'dimensions_count' => $m->dimensions_count, 'measures_count' => $m->measures_count,
            'base_dataset' => $m->baseDataset?->only(['id', 'label', 'row_count', 'freshness_at']), 'updated_at' => $m->updated_at,
        ])]);
    }

    /** Full catalog — the contract the AI agents and the UI build queries against. */
    public function show(Request $request, string $key): JsonResponse
    {
        return response()->json(['data' => $this->present($this->catalogs->get($key), $request)]);
    }

    /** Every metric across models, flattened for search and the AI semantic agent. */
    public function catalog(Request $request): JsonResponse
    {
        $models = SemanticModel::orderBy('name')->get();

        return response()->json(['data' => $models->map(fn ($m) => $this->present($this->catalogs->get($m->id), $request))]);
    }

    public function import(Request $request): JsonResponse
    {
        $def = $request->validate([
            'key' => 'required|regex:/^[a-z_][a-z0-9_]*$/', 'name' => 'required|string', 'base' => 'required|string',
            'dimensions' => 'required|array', 'measures' => 'required|array|min:1', 'metrics' => 'required|array|min:1',
        ]) + $request->all();
        $model = app(SemanticModelImporter::class)->import($request->user()->organisation_id, $def);
        $this->catalogs->forget();
        $this->audit->record('semantic.import', ['resource_type' => 'semantic_model', 'resource_id' => $model->id], ['version' => $model->version]);

        return response()->json(['data' => $this->present($this->catalogs->get($model->id), $request)], 201);
    }

    public function updateMetric(Request $request, string $key, string $metricKey): JsonResponse
    {
        $data = $request->validate([
            'label' => 'sometimes|string|max:120', 'description' => 'sometimes|nullable|string|max:1000',
            'target' => 'sometimes|nullable|numeric', 'synonyms' => 'sometimes|array', 'synonyms.*' => 'string|max:60',
            'is_kpi' => 'sometimes|boolean', 'higher_is_better' => 'sometimes|boolean', 'owner' => 'sometimes|nullable|string|max:120',
        ]);
        $model = SemanticModel::where('key', $key)->firstOrFail();
        $metric = $model->metrics()->where('key', $metricKey)->firstOrFail();
        $metric->update($data);
        $model->increment('version');
        $this->audit->record('semantic.metric_updated', ['resource_type' => 'metric', 'resource_id' => "{$key}.{$metricKey}"], $data);

        return response()->json(['data' => $metric->fresh()]);
    }

    /** Source → table → field → transformation → metric → dashboards/reports. */
    public function lineage(string $key, string $metricKey): JsonResponse
    {
        $catalog = $this->catalogs->get($key);
        $metric = $catalog->metric($metricKey);
        $ref = "{$key}.{$metricKey}";
        $base = $catalog->baseDataset();
        $model = SemanticModel::with('baseDataset.dataSource')->findOrFail($catalog->id);

        $edges = DataLineage::where('to_type', 'metric')->where('to_ref', $ref)->get();
        $dashboards = Dashboard::whereHas('widgets', fn ($q) => $q->where('query->model', $key)->whereJsonContains('query->metrics', $metricKey))->get(['id', 'title']);
        $reports = Report::whereHas('sections', fn ($q) => $q->where('content', 'like', '%'.$ref.'%'))->get(['id', 'title', 'status']);

        return response()->json(['data' => [
            'metric' => ['ref' => $ref, 'label' => $metric['label'], 'expression' => $metric['expression'], 'description' => $metric['description'], 'owner' => $metric['owner']],
            'source' => ['name' => $model->baseDataset->dataSource?->name, 'connector' => $model->baseDataset->dataSource?->connector_key, 'last_sync_at' => $model->baseDataset->dataSource?->last_sync_at],
            'table' => ['name' => $base['schema'].'.'.$base['table'], 'label' => $base['label'], 'freshness_at' => $model->baseDataset->freshness_at, 'row_count' => $model->baseDataset->row_count],
            'transformations' => $edges->map(fn ($e) => ['field' => $e->from_ref, 'transformation' => $e->transformation])->values(),
            'semantic_model' => ['key' => $catalog->key, 'name' => $catalog->name, 'version' => $model->version],
            'dashboards' => $dashboards,
            'reports' => $reports,
        ]]);
    }

    /** @return array<string, mixed> */
    private function present(Catalog $c, Request $request): array
    {
        $canSensitive = $request->user()->hasPermission('data.sensitive');
        $showTech = $request->user()->hasPermission('query.explain');

        return [
            'id' => $c->id, 'key' => $c->key, 'name' => $c->name, 'description' => $c->description, 'time_dimension' => $c->timeDimension,
            'datasets' => $showTech ? array_values(array_map(fn ($d) => ['id' => $d['id'], 'name' => $d['name'], 'label' => $d['label'], 'table' => $d['schema'].'.'.$d['table']], $c->datasets)) : [],
            'dimensions' => array_values(array_map(fn ($d) => [
                'key' => $d['key'], 'label' => $d['label'], 'type' => $d['type'], 'synonyms' => $d['synonyms'], 'description' => $d['description'],
                'is_sensitive' => $d['is_sensitive'], 'accessible' => ! $d['is_sensitive'] || $canSensitive,
                'dataset' => $c->datasets[$d['dataset_id']]['label'] ?? null, 'field' => $showTech ? $d['field'] : null,
            ], $c->dimensions)),
            'measures' => $showTech ? array_values(array_map(fn ($m) => array_intersect_key($m, array_flip(['key', 'label', 'aggregation', 'field', 'filters'])), $c->measures)) : [],
            'metrics' => array_values(array_map(fn ($m) => [
                'key' => $m['key'], 'ref' => $c->key.'.'.$m['key'], 'label' => $m['label'], 'description' => $m['description'], 'format' => $m['format'],
                'higher_is_better' => $m['higher_is_better'], 'target' => $m['target'], 'synonyms' => $m['synonyms'], 'is_kpi' => $m['is_kpi'],
                'owner' => $m['owner'], 'expression' => $showTech ? $m['expression'] : null,
            ], $c->metrics)),
            'hierarchies' => $c->hierarchies,
        ];
    }
}
