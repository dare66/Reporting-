<?php

namespace App\Http\Controllers\Api;

use App\Domain\Analytics\AnomalyService;
use App\Domain\Analytics\ForecastService;
use App\Domain\Analytics\MetricRef;
use App\Domain\Analytics\RootCauseService;
use App\Domain\Analytics\ScenarioService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Query\QueryService;
use App\Domain\Query\SemanticQuery;
use App\Domain\Semantic\CatalogRepository;
use App\Http\Controllers\Controller;
use App\Models\Anomaly;
use App\Models\Forecast;
use App\Models\Scenario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalysisController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function rootCause(Request $request, RootCauseService $service): JsonResponse
    {
        $data = $request->validate(['metric' => 'required|string', 'range' => 'required', 'filters' => 'array', 'dimensions' => 'array', 'compare' => 'in:previous_period,previous_year']);
        $result = $service->explain($data['metric'], $data['range'], $request->user(), $data['filters'] ?? [], $data['dimensions'] ?? null, $data['compare'] ?? 'previous_period');
        $this->audit->record('analysis.root_cause', ['resource_type' => 'metric', 'resource_id' => $data['metric']]);

        return response()->json(['data' => $result]);
    }

    /** Drill-down: the next hierarchy level beneath a selected member. */
    public function drill(Request $request, QueryService $queries, CatalogRepository $catalogs): JsonResponse
    {
        $data = $request->validate(['metric' => 'required|string', 'dimension' => 'required|string', 'value' => 'required', 'range' => 'required', 'filters' => 'array', 'to' => 'nullable|string']);
        $ref = MetricRef::parse($data['metric']);
        $catalog = $catalogs->get($ref->model);
        $next = $data['to'] ?? $catalog->childLevel($data['dimension']);
        if (! $next) {
            return response()->json(['error' => ['code' => 'no_drill_level', 'message' => 'There is no lower level beneath '.$catalog->dimension($data['dimension'])['label'].'.']], 422);
        }
        $filters = [...($data['filters'] ?? []), ['dimension' => $data['dimension'], 'op' => 'eq', 'value' => $data['value']]];
        $query = SemanticQuery::fromArray(['metrics' => [$ref->metric], 'dimensions' => [$next], 'filters' => $filters, 'time' => ['range' => $data['range']], 'sort' => [['key' => $ref->metric, 'dir' => 'desc']], 'limit' => 50]);

        return response()->json(['level' => $next, 'filters' => $filters, 'result' => $queries->runOn($catalog, $query, $request->user())->toArray()]);
    }

    public function forecast(Request $request, ForecastService $service): JsonResponse
    {
        $data = $request->validate(['metric' => 'required|string', 'horizon' => 'required|in:'.implode(',', array_keys(ForecastService::HORIZONS)), 'filters' => 'array']);
        $forecast = $service->forecast($data['metric'], $data['horizon'], $request->user(), $data['filters'] ?? []);
        $this->audit->record('analysis.forecast', ['resource_type' => 'forecast', 'resource_id' => $forecast->id]);

        return response()->json(['data' => $forecast]);
    }

    public function forecasts(): JsonResponse
    {
        return response()->json(['data' => Forecast::latest()->limit(20)->get(['id', 'metric_key', 'grain', 'horizon', 'method', 'diagnostics', 'created_at'])]);
    }

    public function scenario(Request $request, ScenarioService $service): JsonResponse
    {
        $data = $request->validate([
            'demand_change_pct' => 'numeric|between:-90,300', 'officer_change_pct' => 'numeric|between:-90,300',
            'productivity_change_pct' => 'numeric|between:-90,300', 'save_as' => 'nullable|string|max:120',
        ]);
        $result = $service->simulate($data, $request->user());
        if (! empty($data['save_as'])) {
            $result['scenario_id'] = Scenario::create([
                'owner_id' => $request->user()->id, 'name' => $data['save_as'], 'assumptions' => $result['assumptions'],
                'baseline' => $result['baseline'], 'results' => $result['projected'],
            ])->id;
        }

        return response()->json(['data' => $result]);
    }

    public function scenarios(): JsonResponse
    {
        return response()->json(['data' => Scenario::latest()->limit(50)->get()]);
    }

    public function scanAnomalies(Request $request, AnomalyService $service): JsonResponse
    {
        $new = $service->scan($request->user(), $request->input('metrics'));
        $this->audit->record('analysis.anomaly_scan', [], ['new' => count($new)]);

        return response()->json(['detected' => count($new), 'data' => $new]);
    }

    public function anomalies(Request $request): JsonResponse
    {
        $q = Anomaly::query()->orderByDesc('period')->orderByRaw('ABS(score) DESC');
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($metric = $request->query('metric')) {
            $q->where('metric_key', $metric);
        }

        return response()->json(['data' => $q->limit(100)->get()]);
    }

    public function updateAnomaly(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:open,investigating,resolved,dismissed']);
        $anomaly = Anomaly::findOrFail($id);
        $anomaly->update($data);
        $this->audit->record('anomaly.status', ['resource_type' => 'anomaly', 'resource_id' => $id], $data);

        return response()->json(['data' => $anomaly]);
    }
}
