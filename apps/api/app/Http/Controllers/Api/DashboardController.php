<?php

namespace App\Http\Controllers\Api;

use App\Domain\Analytics\KpiService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Dashboards\LayoutReflow;
use App\Domain\Query\QueryService;
use App\Domain\Query\QueryValidationException;
use App\Domain\Query\SemanticQuery;
use App\Domain\Semantic\CatalogRepository;
use App\Http\Controllers\Controller;
use App\Models\Bookmark;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Insight;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    private const WIDGET_TYPES = 'kpi,chart,table,pivot,gauge,insight,text,globe,forecast,anomalies,image,map';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Validation for a dashboard filter list (saved defaults and the viewer's current set).
     *
     * @return array<string, mixed>
     */
    private static function filterRules(): array
    {
        return [
            'filters' => 'sometimes|array|max:20',
            'filters.*.dimension' => 'required|string|max:80',
            'filters.*.op' => ['required', 'string', Rule::in(SemanticQuery::OPERATORS)],
            'filters.*.value' => 'sometimes|nullable', // shape depends on op; SemanticQuery normalises it
            'filters.*.label' => 'sometimes|nullable|string|max:120',
            'filters.*.disabled' => 'sometimes|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $favourites = Bookmark::where('user_id', $request->user()->id)->where('resource_type', 'dashboard')->pluck('resource_id')->all();
        $dashboards = $this->visible($request)->withCount('widgets')->with('owner:id,name')->orderByDesc('is_home')->orderBy('title')->get();

        return response()->json(['data' => $dashboards->map(fn ($d) => $d->only(['id', 'title', 'description', 'theme', 'is_home', 'visibility', 'updated_at', 'widgets_count'])
            + ['owner' => $d->owner?->name, 'is_favourite' => in_array($d->id, $favourites, true), 'can_edit' => $this->canEdit($request, $d)])]);
    }

    public function show(Request $request, string $id, LayoutReflow $reflow, CatalogRepository $catalogs): JsonResponse
    {
        $dashboard = $this->visible($request)->with('widgets')->findOrFail($id);

        return response()->json(['data' => $dashboard->toArray() + [
            'layouts' => $reflow->layouts($dashboard->widgets->map(fn ($w) => $w->only(['id', 'type', 'section', 'priority', 'position']))->all()),
            'filter_dimensions' => $this->filterDimensions($dashboard, $catalogs, $request->user()->hasPermission('data.sensitive')),
            'can_edit' => $this->canEdit($request, $dashboard),
        ]]);
    }

    /**
     * Values a viewer can pick in a dashboard filter. Scoped to the models the
     * dashboard's widgets use, so dashboard viewers need no query permission.
     */
    public function filterMembers(Request $request, string $id, QueryService $queries, CatalogRepository $catalogs): JsonResponse
    {
        $data = $request->validate(['dimension' => 'required|string|max:80', 'search' => 'nullable|string|max:100', 'limit' => 'integer|min:1|max:500']);
        $dashboard = $this->visible($request)->with('widgets')->findOrFail($id);
        $catalog = collect($this->models($dashboard))->map(fn ($m) => $catalogs->get($m))->first(fn ($c) => isset($c->dimensions[$data['dimension']]))
            ?? throw new QueryValidationException("No widget on this dashboard uses '{$data['dimension']}'.");

        return response()->json(['data' => $queries->members($catalog, $data['dimension'], $data['search'] ?? null, (int) ($data['limit'] ?? 200), $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:160', 'description' => 'nullable|string|max:1000', 'theme' => 'nullable|string|max:40',
            'visibility' => 'in:private,organisation', 'sections' => 'array', ...self::filterRules(),
            'widgets' => 'array', 'widgets.*.type' => 'required|in:'.self::WIDGET_TYPES,
        ]);
        $dashboard = DB::transaction(function () use ($data, $request) {
            $d = Dashboard::create([
                'owner_id' => $request->user()->id, 'title' => $data['title'], 'description' => $data['description'] ?? null,
                'theme' => $data['theme'] ?? 'dark-intelligence', 'visibility' => $data['visibility'] ?? 'private',
                'sections' => $data['sections'] ?? [], 'filters' => self::dashboardFilters($data['filters'] ?? []),
            ]);
            foreach ($request->input('widgets', []) as $i => $w) {
                $d->widgets()->create($this->widgetAttributes($w, $i));
            }

            return $d;
        });
        $this->audit->record('dashboard.created', ['resource_type' => 'dashboard', 'resource_id' => $dashboard->id]);

        return response()->json(['data' => $dashboard->load('widgets')], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $dashboard = $this->editable($request, $id);
        $data = $request->validate([
            'title' => 'sometimes|string|max:160', 'description' => 'sometimes|nullable|string|max:1000', 'theme' => 'sometimes|string|max:40',
            'visibility' => 'sometimes|in:private,organisation', 'sections' => 'sometimes|array', ...self::filterRules(),
        ]);
        if (array_key_exists('filters', $data)) {
            $data['filters'] = self::dashboardFilters($data['filters']);
        }
        $dashboard->update($data);

        return response()->json(['data' => $dashboard->load('widgets')]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->editable($request, $id)->delete();
        $this->audit->record('dashboard.deleted', ['resource_type' => 'dashboard', 'resource_id' => $id]);

        return response()->json(null, 204);
    }

    /** Bulk position update from the canvas (drag/resize). */
    public function saveLayout(Request $request, string $id): JsonResponse
    {
        $dashboard = $this->editable($request, $id);
        $data = $request->validate(['widgets' => 'required|array', 'widgets.*.id' => 'required|uuid', 'widgets.*.x' => 'required|integer|min:0|max:11',
            'widgets.*.y' => 'required|integer|min:0', 'widgets.*.w' => 'required|integer|min:1|max:12', 'widgets.*.h' => 'required|integer|min:1|max:20']);
        DB::transaction(function () use ($dashboard, $data) {
            foreach ($data['widgets'] as $w) {
                $dashboard->widgets()->where('id', $w['id'])->update(['position' => json_encode(['x' => $w['x'], 'y' => $w['y'], 'w' => min($w['w'], 12 - $w['x']), 'h' => $w['h']])]);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function addWidget(Request $request, string $id): JsonResponse
    {
        $dashboard = $this->editable($request, $id);
        $data = $request->validate(['type' => 'required|in:'.self::WIDGET_TYPES, 'title' => 'nullable|string|max:160', 'section' => 'nullable|string',
            'query' => 'array', 'viz' => 'array', 'position' => 'array', 'priority' => 'integer']);
        $widget = $dashboard->widgets()->create($this->widgetAttributes($data, $dashboard->widgets()->count()));

        return response()->json(['data' => $widget], 201);
    }

    public function updateWidget(Request $request, string $id, string $widgetId): JsonResponse
    {
        $widget = $this->editable($request, $id)->widgets()->findOrFail($widgetId);
        $widget->update($request->validate(['title' => 'sometimes|nullable|string|max:160', 'section' => 'sometimes|nullable|string',
            'query' => 'sometimes|array', 'viz' => 'sometimes|array', 'position' => 'sometimes|array', 'priority' => 'sometimes|integer', 'type' => 'sometimes|in:'.self::WIDGET_TYPES]));

        return response()->json(['data' => $widget]);
    }

    public function deleteWidget(Request $request, string $id, string $widgetId): JsonResponse
    {
        $this->editable($request, $id)->widgets()->where('id', $widgetId)->delete();

        return response()->json(null, 204);
    }

    /**
     * Executes a widget's query with the dashboard filters merged in.
     *
     * The viewer's current filter set (request) replaces the saved defaults; a
     * dashboard filter only reaches widgets whose model has that dimension, the
     * way a Sisense dashboard filter applies to widgets of the same data model.
     */
    public function widgetData(Request $request, string $id, string $widgetId, QueryService $queries, KpiService $kpis, CatalogRepository $catalogs): JsonResponse
    {
        $request->validate(self::filterRules());
        $dashboard = $this->visible($request)->findOrFail($id);
        /** @var DashboardWidget $widget */
        $widget = $dashboard->widgets()->findOrFail($widgetId);
        $q = $widget->query;
        $dashboardFilters = self::activeFilters($request->has('filters') ? (array) $request->input('filters') : ($dashboard->filters ?? []));
        $range = $request->input('range') ?? ($q['time']['range'] ?? null);

        if (in_array($widget->type, ['insight', 'text', 'image'], true)) {
            return response()->json($widget->type === 'insight'
                ? ['kind' => 'insights', 'data' => Insight::orderByDesc('created_at')->limit(6)->get()]
                : ['kind' => 'static', 'data' => $widget->viz]);
        }

        $catalog = $catalogs->get($q['model']);
        $scoped = array_values(array_filter($dashboardFilters, fn ($f) => isset($catalog->dimensions[$f['dimension']])));
        $filters = [...SemanticQuery::normaliseFilters($q['filters'] ?? []), ...$scoped];

        return response()->json($widget->type === 'kpi'
            ? ['kind' => 'kpi', 'data' => $kpis->cards(array_map(fn ($m) => $q['model'].'.'.$m, $q['metrics']), $range ?? 'last_30_days', $request->user(), $filters, $widget->viz['compare'] ?? 'previous_period')]
            : ['kind' => 'query', 'data' => $queries->runOn($catalog, SemanticQuery::fromArray(array_merge($q, ['filters' => $filters, 'time' => array_filter(['grain' => $q['time']['grain'] ?? null, 'range' => $range])])), $request->user())->toArray()]);
    }

    /**
     * Dashboard filters as stored: the normalised filter plus its display label and paused state.
     *
     * @param  array<mixed>  $input
     * @return list<array<string, mixed>>
     */
    private static function dashboardFilters(array $input): array
    {
        return array_map(fn ($raw, $f) => $f + array_filter([
            'label' => $raw['label'] ?? null,
            'disabled' => (bool) ($raw['disabled'] ?? false) ?: null,
        ], fn ($v) => $v !== null), array_values($input), SemanticQuery::normaliseFilters($input));
    }

    /**
     * @param  array<mixed>  $filters
     * @return list<array{dimension: string, op: string, value?: mixed}>
     */
    private static function activeFilters(array $filters): array
    {
        return SemanticQuery::normaliseFilters(array_filter($filters, fn ($f) => ! (is_array($f) && ($f['disabled'] ?? false))));
    }

    /**
     * @param  array<string, mixed>  $w  validated widget payload
     * @return array<string, mixed>
     */
    private function widgetAttributes(array $w, int $index): array
    {
        return [
            'type' => $w['type'], 'title' => $w['title'] ?? null, 'section' => $w['section'] ?? null,
            'query' => $w['query'] ?? [], 'viz' => $w['viz'] ?? [],
            'position' => $w['position'] ?? ['x' => ($index * 6) % 12, 'y' => intdiv($index, 2) * 4, 'w' => 6, 'h' => 4],
            'priority' => $w['priority'] ?? ($index + 1) * 10,
        ];
    }

    /** @return list<string> distinct semantic models queried by the dashboard's widgets */
    private function models(Dashboard $dashboard): array
    {
        return $dashboard->widgets->map(fn ($w) => $w->query['model'] ?? null)->filter()->unique()->values()->all();
    }

    /**
     * Dimensions shared by the dashboard's models that a filter can target, with the models they apply to.
     *
     * @return list<array{key: string, label: string, type: string, models: list<string>}>
     */
    private function filterDimensions(Dashboard $dashboard, CatalogRepository $catalogs, bool $canSeeSensitive): array
    {
        $dimensions = [];
        foreach ($this->models($dashboard) as $model) {
            $catalog = $catalogs->get($model);
            foreach ($catalog->dimensions as $d) {
                if ($d['key'] === $catalog->timeDimension || ($d['is_sensitive'] && ! $canSeeSensitive)) {
                    continue;
                }
                $dimensions[$d['key']] ??= ['key' => $d['key'], 'label' => $d['label'], 'type' => $d['type'], 'models' => []];
                $dimensions[$d['key']]['models'][] = $catalog->key;
            }
        }
        // Dimensions that span more of the dashboard first, then alphabetical.
        usort($dimensions, fn ($a, $b) => count($b['models']) <=> count($a['models']) ?: strcmp($a['label'], $b['label']));

        return $dimensions;
    }

    /** @return Builder<Dashboard> */
    private function visible(Request $request): Builder
    {
        return Dashboard::query()->where(fn ($q) => $q->where('visibility', 'organisation')->orWhere('owner_id', $request->user()->id));
    }

    private function editable(Request $request, string $id): Dashboard
    {
        $dashboard = $this->visible($request)->findOrFail($id);
        abort_unless($this->canEdit($request, $dashboard), 403, 'You can view this dashboard but not edit it.');

        return $dashboard;
    }

    private function canEdit(Request $request, Dashboard $d): bool
    {
        $u = $request->user();

        return $u->hasPermission('dashboards.manage') && ($d->owner_id === $u->id || $u->hasPermission('admin.org') || $d->visibility === 'organisation');
    }
}
