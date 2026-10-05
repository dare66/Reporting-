<?php

namespace App\Domain\AutoBi;

use App\Domain\Audit\AuditLogger;
use App\Domain\Query\TimeRange;
use App\Domain\Reports\ReportBuilder;
use App\Domain\Reports\ReportVersioning;
use App\Domain\Semantic\SemanticModelGenerator;
use App\Domain\Semantic\SemanticModelImporter;
use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Auto BI: from a connected source to a governed semantic model, a dashboard
 * and an executive report. `plan()` only reads and proposes; `publish()` writes
 * what a person approved. Every proposal carries its reason and confidence, and
 * every number on the published dashboard and report comes from governed
 * queries against the source's real data.
 *
 * @phpstan-import-type FieldUnderstanding from DataUnderstanding
 * @phpstan-import-type Kpi from KpiDiscovery
 * @phpstan-import-type Relationship from RelationshipDiscovery
 *
 * @phpstan-type DimensionChoice array{key: string, label: string, field: string, dataset: string, role: string, distinct: int, values: list<string>}
 * @phpstan-type Window array{from: string, to: string, grain: string, span_days: int}
 * @phpstan-type WidgetPlan array{type: string, title: string, purpose: string, rationale: string, query: array<string, mixed>, viz: array<string, mixed>, position: array{x: int, y: int, w: int, h: int}}
 */
class AutoBiDesigner
{
    public const AUDIENCES = ['executive', 'operations'];

    public function __construct(
        private readonly DataUnderstanding $understanding,
        private readonly RelationshipDiscovery $relationships,
        private readonly KpiDiscovery $kpis,
        private readonly DataTrust $trust,
        private readonly SemanticModelGenerator $generator,
        private readonly SemanticModelImporter $importer,
        private readonly ReportBuilder $reports,
        private readonly ReportVersioning $versions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The full proposal: understanding, relationships, KPIs, trust, and the dashboard and report it would build.
     *
     * @param  list<string>|null  $kpiKeys  KPIs to design with, in order; null = the recommended ones
     * @param  array<string, string|null>  $labels  renamed KPI labels, keyed by KPI key
     * @return array<string, mixed>
     */
    public function plan(DataSource $source, string $audience = 'executive', ?array $kpiKeys = null, array $labels = []): array
    {
        $a = $this->analyse($source);
        $chosen = $kpiKeys === null
            ? array_values(array_filter($a['kpis'], fn ($k) => $k['recommended']))
            : $this->choose($a['kpis'], $kpiKeys, $labels);

        return [
            'source' => ['id' => $source->id, 'name' => $source->name],
            'model_key' => $a['model'],
            'fact' => ['name' => $a['fact']->name, 'label' => $a['fact']->label, 'rows' => (int) $a['fact']->row_count],
            'entity' => $a['entity'],
            'domain' => $a['domain'],
            'datasets' => $a['datasets']->map(fn (Dataset $d) => [
                'id' => $d->id, 'name' => $d->name, 'label' => $d->label, 'rows' => (int) $d->row_count, 'is_fact' => $d->id === $a['fact']->id,
                'fields' => $a['understood'][$d->name]['fields'], 'trust' => $this->trust->assess($d),
            ])->values()->all(),
            'relationships' => $a['relationships'],
            'kpis' => $a['kpis'],
            'window' => $a['window'],
            'questions' => $this->questions($a['understood'][$a['fact']->name], $a['understood']),
            'dashboard' => $this->dashboard($a['model'], $chosen, $a['dims'], $a['window'], $audience),
            'report' => $this->reportSections($a['model'], $chosen, $a['dims'], $a['window']),
        ];
    }

    /**
     * Writes the approved design: semantic model, dashboard and report.
     *
     * @param  list<string>  $kpiKeys  approved KPI keys, in the order to show them
     * @param  array<string, string|null>  $labels  optional renamed KPI labels, keyed by KPI key
     * @return array{model: string, dashboard_id: string, report_id: string}
     */
    public function publish(DataSource $source, array $kpiKeys, string $audience, User $user, array $labels = []): array
    {
        $a = $this->analyse($source);
        $chosen = $this->choose($a['kpis'], $kpiKeys, $labels);
        if ($chosen === []) {
            throw new InvalidArgumentException('Approve at least one KPI.');
        }
        $fact = $a['fact'];
        $dims = $a['dims'];
        $plan = ['fact' => ['label' => $fact->label], 'domain' => $a['domain'], 'window' => $a['window']];

        $definition = $this->definition($source, $a, $chosen);
        $model = $this->importer->import($user->organisation_id, $definition, $user);
        $dashboardPlan = $this->dashboard($model->key, $chosen, $dims, $plan['window'], $audience);
        $reportPlan = $this->reportSections($model->key, $chosen, $dims, $plan['window']);
        $title = Str::headline(preg_replace('/\s*\(upload\)$/i', '', $source->name) ?? $source->name);

        $dashboard = DB::transaction(function () use ($dashboardPlan, $title, $audience, $plan, $user, $source) {
            $d = Dashboard::create([
                'project_id' => $source->project_id, 'owner_id' => $user->id, 'title' => "{$title} — ".Str::headline($audience).' overview',
                'description' => "Designed automatically from {$plan['fact']['label']} ({$plan['domain']['name']}). Each widget records why it was chosen.",
                'visibility' => 'private', 'sections' => [['key' => 'main', 'label' => 'Overview']], 'filters' => [],
            ]);
            foreach ($dashboardPlan['widgets'] as $i => $w) {
                $d->widgets()->create(['type' => $w['type'], 'title' => $w['title'], 'section' => 'main', 'query' => $w['query'],
                    'viz' => $w['viz'] + ['rationale' => $w['rationale']], 'position' => $w['position'], 'priority' => ($i + 1) * 10]);
            }

            return $d;
        });

        $period = TimeRange::resolve($reportPlan['range'] ?? 'last_30_days');
        $report = Report::create([
            'project_id' => $source->project_id, 'owner_id' => $user->id, 'title' => "{$title} — executive report", 'subtitle' => $period->label,
            'type' => 'management', 'theme' => 'executive', 'status' => 'draft',
            'parameters' => ['range' => $reportPlan['range'] ?? 'last_30_days', 'range_label' => $period->label, 'period' => $period->toArray()],
        ]);
        $this->reports->build($report, $reportPlan['sections'], $user);
        $this->versions->snapshot($report, $user, 'Generated by Auto BI');

        $this->audit->record('auto_bi.published', ['resource_type' => 'data_source', 'resource_id' => $source->id], [
            'model' => $model->key, 'dashboard' => $dashboard->id, 'report' => $report->id, 'kpis' => $kpiKeys, 'audience' => $audience,
        ]);

        return ['model' => $model->key, 'dashboard_id' => $dashboard->id, 'report_id' => $report->id];
    }

    /**
     * @param  list<Kpi>  $kpis
     * @param  list<string>  $keys
     * @param  array<string, string|null>  $labels
     * @return list<Kpi>
     */
    private function choose(array $kpis, array $keys, array $labels): array
    {
        $byKey = [];
        foreach ($kpis as $k) {
            $byKey[$k['key']] = $k;
        }
        $chosen = [];
        foreach ($keys as $key) {
            $kpi = $byKey[$key] ?? throw new InvalidArgumentException("Unknown KPI {$key}.");
            if (trim((string) ($labels[$key] ?? '')) !== '') {
                $kpi['label'] = Str::limit(trim((string) $labels[$key]), 80, '');
            }
            $chosen[] = $kpi;
        }

        return $chosen;
    }

    /**
     * Everything the proposal and the publication are built from.
     *
     * @return array{
     *     datasets: Collection<int, Dataset>, understood: array<string, array{fields: list<FieldUnderstanding>, entity: string, domain: array{name: string, confidence: float, evidence: list<string>}}>,
     *     relationships: list<Relationship>, fact: Dataset, entity: string, domain: array{name: string, confidence: float, evidence: list<string>},
     *     kpis: list<Kpi>, dims: list<DimensionChoice>, window: Window|null, model: string
     * }
     */
    private function analyse(DataSource $source): array
    {
        $datasets = $this->datasets($source);
        $understood = [];
        foreach ($datasets as $d) {
            $understood[$d->name] = $this->understanding->understand($d);
        }
        $relationships = $this->relationships->discover($datasets);
        $fact = $this->fact($datasets, $understood, $relationships);
        $u = $understood[$fact->name];

        return [
            'datasets' => $datasets, 'understood' => $understood, 'relationships' => $relationships, 'fact' => $fact,
            'entity' => $u['entity'], 'domain' => $u['domain'], 'kpis' => $this->kpis->discover($u['entity'], $u['fields']),
            'dims' => $this->dimensions($fact, $datasets, $understood, $relationships), 'window' => $this->window($fact, $u['fields']),
            'model' => $this->modelKey($source),
        ];
    }

    /** @return Collection<int, Dataset> */
    private function datasets(DataSource $source): Collection
    {
        $datasets = Dataset::with(['fields', 'dataSource'])->where('data_source_id', $source->id)->orderBy('created_at')->get();
        if ($datasets->isEmpty()) {
            throw new InvalidArgumentException('This source has no datasets yet. Load data first.');
        }

        return $datasets;
    }

    /**
     * The table the dashboard is about: the one that refers to the others
     * (the "fact"), then the one with a time axis, then the largest.
     *
     * @param  Collection<int, Dataset>  $datasets
     * @param  array<string, array{fields: list<FieldUnderstanding>}>  $understood
     * @param  list<Relationship>  $relationships
     */
    private function fact(Collection $datasets, array $understood, array $relationships): Dataset
    {
        return $datasets->sortByDesc(fn (Dataset $d) => [
            // Only many-to-one references make a fact; a one-to-one link is a subset or extension of another table.
            count(array_filter($relationships, fn ($r) => $r['from_dataset'] === $d->name && $r['cardinality'] === 'many_to_one')),
            collect($understood[$d->name]['fields'])->contains('role', 'time') ? 1 : 0,
            (int) $d->row_count,
        ])->first();
    }

    private function modelKey(DataSource $source): string
    {
        $base = Str::snake(Str::ascii(preg_replace('/\s*\(upload\)$/i', '', $source->name) ?? 'source'));
        // Model keys are unique per organisation, so a model outside the default project carries its project's key.
        $project = $source->project()->withoutGlobalScopes()->first();
        $prefix = $project === null || $project->is_default ? 'auto_' : 'auto_'.$project->key.'_';

        return Str::limit($prefix.trim((string) preg_replace('/[^a-z0-9_]+/', '_', $base), '_'), 40, '');
    }

    /**
     * Grouping columns of the fact table and of the tables it refers to.
     *
     * @param  Collection<int, Dataset>  $datasets
     * @param  array<string, array{fields: list<FieldUnderstanding>}>  $understood
     * @param  list<Relationship>  $relationships
     * @return list<DimensionChoice>
     */
    private function dimensions(Dataset $fact, Collection $datasets, array $understood, array $relationships): array
    {
        $out = [];
        $add = function (Dataset $ds, bool $joined) use (&$out, $understood) {
            foreach ($understood[$ds->name]['fields'] as $f) {
                if (! in_array($f['role'], ['dimension', 'geography', 'status'], true)) {
                    continue;
                }
                $key = Str::snake($f['field']);
                if ($joined && collect($out)->contains('key', $key)) {
                    $key = Str::snake(Str::singular(preg_replace('/^ds_[a-z0-9]+_/', '', $ds->name) ?? '')).'_'.$key;
                }
                $field = $ds->fields->firstWhere('name', $f['field']);
                $out[] = ['key' => $key, 'label' => $f['label'], 'field' => $f['field'], 'dataset' => $ds->name, 'role' => $f['role'],
                    'distinct' => (int) ($field->profile['distinct'] ?? 0), 'values' => $f['values'] ?? []];
            }
        };
        $add($fact, false);
        foreach ($relationships as $r) {
            if ($r['from_dataset'] === $fact->name && ($to = $datasets->firstWhere('name', $r['to_dataset']))) {
                $add($to, true);
            }
        }

        return $out;
    }

    /**
     * The period the data covers, so queries look where the data is (an
     * uploaded extract may end months ago) rather than at "the last 30 days".
     *
     * @param  list<FieldUnderstanding>  $fields
     * @return Window|null
     */
    private function window(Dataset $fact, array $fields): ?array
    {
        $time = collect($fields)->firstWhere('role', 'time');
        $field = $time ? $fact->fields->firstWhere('name', $time['field']) : null;
        if (! $field || empty($field->profile['min']) || empty($field->profile['max'])) {
            return null;
        }
        $min = CarbonImmutable::parse(substr((string) $field->profile['min'], 0, 10));
        $max = CarbonImmutable::parse(substr((string) $field->profile['max'], 0, 10));
        $span = (int) $min->diffInDays($max);

        return [
            'from' => $max->subMonthsNoOverflow(24)->max($min)->toDateString(), 'to' => $max->toDateString(),
            'grain' => $span > 120 ? 'month' : ($span > 30 ? 'week' : 'day'), 'span_days' => $span,
        ];
    }

    /**
     * The most recent stretch of the data for KPI cards: the last 30 days
     * (compared with the 30 before) when there is enough history, else all of it.
     *
     * @param  Window|null  $window
     * @return array{from: string, to: string}|null
     */
    private function kpiRange(?array $window): ?array
    {
        if ($window === null) {
            return null;
        }
        $to = CarbonImmutable::parse($window['to']);

        return $window['span_days'] >= 60 ? ['from' => $to->subDays(29)->toDateString(), 'to' => $window['to']] : ['from' => $window['from'], 'to' => $window['to']];
    }

    /**
     * Places widgets to answer, in reading order: what happened (KPIs), how it
     * is changing (trend), what it is made of (composition), where (ranking),
     * what needs attention, and the detail behind it.
     *
     * @param  list<Kpi>  $kpis
     * @param  list<DimensionChoice>  $dims
     * @param  Window|null  $window
     * @return array{audience: string, widgets: list<WidgetPlan>}
     */
    private function dashboard(string $model, array $kpis, array $dims, ?array $window, string $audience): array
    {
        $widgets = [];
        $y = 0;
        $kpiRange = $this->kpiRange($window);
        $time = fn (?string $grain = null) => $window ? array_filter(['range' => ['from' => $window['from'], 'to' => $window['to']], 'grain' => $grain]) : [];
        $recent = $kpiRange ? ['range' => $kpiRange] : [];
        $primary = $kpis[0] ?? null;
        if ($primary === null) {
            return ['audience' => $audience, 'widgets' => []];
        }

        $cards = array_slice($kpis, 0, 4);
        $w = intdiv(12, max(1, count($cards)));
        foreach ($cards as $i => $k) {
            $widgets[] = ['type' => 'kpi', 'title' => $k['label'], 'purpose' => 'What happened',
                'rationale' => 'KPI card: the single number leaders check first'.($kpiRange ? ', compared with the previous period.' : '.'),
                'query' => ['model' => $model, 'metrics' => [$k['key']], 'time' => $recent], 'viz' => $window ? ['compare' => 'previous_period'] : [],
                'position' => ['x' => $i * $w, 'y' => $y, 'w' => $w, 'h' => 2]];
        }
        $y += 2;

        $ranked = $this->rankDimensions($dims);
        $status = collect($dims)->first(fn ($d) => $d['role'] === 'status' && $d['distinct'] <= 8);
        $hasSide = $status || $ranked;
        if ($window) {
            $widgets[] = ['type' => 'chart', 'title' => "{$primary['label']} over time", 'purpose' => 'How it is changing',
                'rationale' => 'Trend → line: change over time reads best as a continuous line.',
                'query' => ['model' => $model, 'metrics' => [$primary['key']], 'time' => $time($window['grain'])],
                'viz' => ['type' => $audience === 'executive' ? 'area' : 'line'], 'position' => ['x' => 0, 'y' => $y, 'w' => $hasSide ? 8 : 12, 'h' => 4]];
        }
        if ($status) {
            $widgets[] = ['type' => 'chart', 'title' => "{$primary['label']} by {$status['label']}", 'purpose' => 'What it is made of',
                'rationale' => "Composition → donut: {$status['label']} has {$status['distinct']} parts that add up to the whole.",
                'query' => ['model' => $model, 'metrics' => [$primary['key']], 'dimensions' => [$status['key']], 'time' => $time()],
                'viz' => ['type' => 'donut'], 'position' => ['x' => $window ? 8 : 0, 'y' => $y, 'w' => $window ? 4 : 6, 'h' => 4]];
        } elseif ($ranked && $window) {
            $d = $ranked[0];
            $widgets[] = ['type' => 'chart', 'title' => "{$primary['label']} by {$d['label']}", 'purpose' => 'What it is made of',
                'rationale' => "Comparison → bar: {$d['label']} has few enough members to compare side by side.",
                'query' => ['model' => $model, 'metrics' => [$primary['key']], 'dimensions' => [$d['key']], 'time' => $time(), 'sort' => [['key' => $primary['key'], 'dir' => 'desc']], 'limit' => 8],
                'viz' => ['type' => 'bar', 'orientation' => 'vertical'], 'position' => ['x' => 8, 'y' => $y, 'w' => 4, 'h' => 4]];
        }
        $y += 4;

        if ($ranked) {
            $where = $ranked[0];
            $widgets[] = ['type' => 'chart', 'title' => "Top {$where['label']} by {$primary['label']}", 'purpose' => 'Where',
                'rationale' => $where['role'] === 'geography'
                    ? "Geography → ranked bar: shows which places contribute most to {$primary['label']}."
                    : 'Ranking → horizontal bar: long names stay readable and the largest contributors come first.',
                'query' => ['model' => $model, 'metrics' => [$primary['key']], 'dimensions' => [$where['key']], 'time' => $time(), 'sort' => [['key' => $primary['key'], 'dir' => 'desc']], 'limit' => 10],
                'viz' => ['type' => 'bar', 'orientation' => 'horizontal'], 'position' => ['x' => 0, 'y' => $y, 'w' => 6, 'h' => 5]];

            $watch = collect($kpis)->first(fn ($k) => $k['kind'] === 'rate' || $k['kind'] === 'duration') ?? ($kpis[1] ?? $primary);
            $by = $ranked[1] ?? $where;
            $worstFirst = $watch['higher_is_better'] ? 'asc' : 'desc';
            $widgets[] = ['type' => 'chart', 'title' => "{$watch['label']} by {$by['label']} — needs attention first", 'purpose' => 'What needs attention',
                'rationale' => "Ranking, worst first: {$by['label']} members where {$watch['label']} is ".($watch['higher_is_better'] ? 'lowest' : 'highest').' lead.',
                'query' => ['model' => $model, 'metrics' => [$watch['key']], 'dimensions' => [$by['key']], 'time' => $time(), 'sort' => [['key' => $watch['key'], 'dir' => $worstFirst]], 'limit' => 10],
                'viz' => ['type' => 'bar', 'orientation' => 'horizontal'], 'position' => ['x' => 6, 'y' => $y, 'w' => 6, 'h' => 5]];
            $y += 5;

            if ($audience === 'operations' && $window) {
                $widgets[] = ['type' => 'chart', 'title' => "{$primary['label']} — {$where['label']} × ".Str::headline($window['grain']), 'purpose' => 'How it is changing',
                    'rationale' => 'Time × category → heatmap: shows which members drive changes in which periods.',
                    'query' => ['model' => $model, 'metrics' => [$primary['key']], 'dimensions' => [$where['key']], 'time' => $time($window['grain'] === 'day' ? 'week' : $window['grain'])],
                    'viz' => ['type' => 'heatmap'], 'position' => ['x' => 0, 'y' => $y, 'w' => 12, 'h' => 6]];
                $y += 6;
            }

            $widgets[] = ['type' => 'table', 'title' => "{$where['label']} scorecard", 'purpose' => 'The detail',
                'rationale' => 'Table: every KPI side by side for each member, for people who need exact values.',
                'query' => ['model' => $model, 'metrics' => array_column(array_slice($kpis, 0, 5), 'key'), 'dimensions' => [$where['key']], 'time' => $time(),
                    'sort' => [['key' => $primary['key'], 'dir' => 'desc']], 'limit' => $audience === 'operations' ? 50 : 20],
                'viz' => ['type' => 'table'], 'position' => ['x' => 0, 'y' => $y, 'w' => 12, 'h' => 6]];
        }

        return ['audience' => $audience, 'widgets' => $widgets];
    }

    /**
     * Grouping columns most worth ranking by: places first, then columns with a
     * readable number of members; columns with hundreds of members come last.
     *
     * @param  list<DimensionChoice>  $dims
     * @return list<DimensionChoice>
     */
    private function rankDimensions(array $dims): array
    {
        $candidates = array_values(array_filter($dims, fn ($d) => $d['role'] !== 'status' && $d['distinct'] >= 2));

        return collect($candidates)->sortByDesc(fn ($d) => [
            $d['role'] === 'geography' ? 1 : 0,
            $d['distinct'] >= 3 && $d['distinct'] <= 40 ? 1 : 0,
            -$d['distinct'],
        ])->values()->all();
    }

    /**
     * @param  list<Kpi>  $kpis
     * @param  list<DimensionChoice>  $dims
     * @param  Window|null  $window
     * @return array{range: array{from: string, to: string}|null, sections: list<array<string, mixed>>}
     */
    private function reportSections(string $model, array $kpis, array $dims, ?array $window): array
    {
        $ref = fn (array $k) => "{$model}.{$k['key']}";
        $primary = $kpis[0] ?? null;
        $ranked = $this->rankDimensions($dims);
        $sections = [['type' => 'summary', 'title' => 'Executive summary']];
        if ($primary) {
            $sections[] = ['type' => 'kpis', 'title' => 'Key measures', 'metrics' => array_map($ref, array_slice($kpis, 0, 6))];
            if ($window) {
                $sections[] = ['type' => 'chart', 'title' => "{$primary['label']} trend", 'metric' => $ref($primary), 'grain' => $window['grain'],
                    'range' => ['from' => $window['from'], 'to' => $window['to']], 'chart' => 'area'];
            }
            foreach (array_slice($ranked, 0, 2) as $d) {
                $sections[] = ['type' => 'breakdown', 'title' => "{$primary['label']} by {$d['label']}", 'metric' => $ref($primary), 'dimension' => $d['key'], 'limit' => 10];
            }
            if ($window && $ranked) {
                $sections[] = ['type' => 'root_cause', 'title' => "What drove the change in {$primary['label']}", 'metric' => $ref($primary)];
            }
            // Forecasts train on history up to today, so only data that is current can be projected.
            if ($window && $window['span_days'] >= 180 && CarbonImmutable::parse($window['to'])->diffInDays(now()) <= 60) {
                $sections[] = ['type' => 'forecast', 'title' => "{$primary['label']} outlook", 'metric' => $ref($primary), 'horizon' => 6];
            }
        }
        $sections[] = ['type' => 'risks', 'title' => 'Risks and watch-points'];

        return ['range' => $this->kpiRange($window), 'sections' => $sections];
    }

    /**
     * The semantic model: approved KPIs as governed metrics, every other numeric
     * column as plain measures, and the grouping columns of related tables.
     *
     * @param  array{fact: Dataset, understood: array<string, array{fields: list<FieldUnderstanding>}>, relationships: list<Relationship>, dims: list<DimensionChoice>, domain: array{name: string}, model: string}  $a
     * @param  list<Kpi>  $kpis
     * @return array<string, mixed>
     */
    private function definition(DataSource $source, array $a, array $kpis): array
    {
        $fact = $a['fact'];
        $generated = $this->generator->propose($fact);
        $timeFields = array_values(array_filter($a['understood'][$fact->name]['fields'], fn ($f) => $f['role'] === 'time'));

        $measures = [];
        foreach ([...array_merge(...array_column($kpis, 'measures')), ...$generated['measures']] as $m) {
            $measures[$m['key']] ??= $m;
        }
        $kpiMetrics = array_map(fn ($k) => [
            // The person publishing approved these KPIs on the way in; certification is a separate, second step.
            'key' => $k['key'], 'label' => $k['label'], 'expression' => $k['expression'], 'format' => $k['format'], 'is_kpi' => true, 'status' => 'approved',
            'higher_is_better' => $k['higher_is_better'], 'synonyms' => [Str::lower($k['label'])],
            'description' => "{$k['formula']}. {$k['reason']}",
        ], $kpis);
        $taken = array_column($kpiMetrics, 'key');
        $extra = array_map(fn ($m) => ['is_kpi' => false] + $m, array_values(array_filter($generated['metrics'], fn ($m) => ! in_array($m['key'], $taken, true))));
        $name = preg_replace('/\s*\(upload\)$/i', '', $source->name) ?? $source->name;

        return [
            'key' => $a['model'],
            'name' => Str::headline($name),
            'description' => "Generated by Auto BI from {$fact->label}. Detected domain: {$a['domain']['name']}.",
            'domain' => $a['domain']['name'],
            'base' => $fact->name,
            'time_dimension' => $timeFields[0]['field'] ?? null,
            'relationships' => array_values(array_map(fn ($r) => ['from' => $r['from'], 'to' => $r['to']],
                array_filter($a['relationships'], fn ($r) => $r['from_dataset'] === $fact->name))),
            'dimensions' => [
                ...array_map(fn ($f) => ['key' => $f['field'], 'label' => $f['label'], 'field' => $f['field'], 'type' => 'time', 'root_cause' => false], $timeFields),
                ...array_map(fn ($d) => ['key' => $d['key'], 'label' => $d['label'], 'field' => $d['field'], 'dataset' => $d['dataset'], 'type' => 'string',
                    'synonyms' => [Str::lower($d['label'])], 'root_cause' => $d['distinct'] <= 200], $a['dims']),
            ],
            'measures' => array_values($measures),
            'metrics' => [...$kpiMetrics, ...$extra],
        ];
    }

    /**
     * Where the system is unsure, it asks rather than assumes.
     *
     * @param  array{domain: array{name: string, confidence: float, evidence: list<string>}, fields: list<FieldUnderstanding>}  $fact
     * @param  array<string, array{fields: list<FieldUnderstanding>}>  $understood
     * @return list<string>
     */
    private function questions(array $fact, array $understood): array
    {
        $q = [];
        if ($fact['domain']['confidence'] < 0.6) {
            $q[] = 'What is this data about? The column names do not point clearly to a business domain.';
        }
        foreach ($understood as $u) {
            foreach ($u['fields'] as $f) {
                if ($f['confidence'] < 0.75 && $f['role'] !== 'ignored') {
                    $role = str_replace('_', ' ', $f['role']);
                    $q[] = "Is {$f['label']} ".(preg_match('/^[aeiou]/i', $role) ? 'an' : 'a')." {$role}? ".($f['reasons'][0] ?? '');
                }
            }
        }

        return array_slice($q, 0, 6);
    }
}
