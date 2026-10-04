<?php

namespace App\Http\Controllers\Api;

use App\Domain\Metrics\MetricDefinition;
use App\Domain\Metrics\MetricStore;
use App\Domain\Metrics\MetricUsage;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Metric;
use App\Models\MetricVersion;
use App\Models\SemanticModel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The metric store: every governed metric, its lifecycle, owners, versions and usage. */
class MetricStoreController extends Controller
{
    public function __construct(private readonly MetricStore $store, private readonly MetricDefinition $definitions, private readonly MetricUsage $usage) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(Metric::STATUSES)], 'model' => 'nullable|string|max:80', 'q' => 'nullable|string|max:100', 'kpi' => 'nullable|boolean']);
        $usage = $this->usage->index($request->user());
        $metrics = Metric::query()->whereHas('semanticModel')
            ->with(['semanticModel:id,key,name', 'businessOwner:id,name', 'dataOwner:id,name', 'certifier:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['model'] ?? null, fn ($q, $m) => $q->whereHas('semanticModel', fn ($q) => $q->where('key', $m)))
            ->when(isset($filters['kpi']), fn ($q) => $q->where('is_kpi', (bool) $filters['kpi']))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(fn ($q) => $q->where('label', 'ilike', '%'.addcslashes($text, '%_\\').'%')
                ->orWhere('key', 'ilike', '%'.addcslashes($text, '%_\\').'%')->orWhere('description', 'ilike', '%'.addcslashes($text, '%_\\').'%')))
            ->orderByRaw("CASE status WHEN 'certified' THEN 0 WHEN 'approved' THEN 1 WHEN 'proposed' THEN 2 ELSE 3 END")->orderByDesc('is_kpi')->orderBy('label')
            ->get();
        $counts = Metric::query()->whereHas('semanticModel')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'data' => $metrics->map(fn (Metric $m) => $this->summary($m) + ['usage' => $this->usageCount($usage[$this->store->ref($m)] ?? null)])->values(),
            'counts' => collect(Metric::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
        ]);
    }

    public function show(Request $request, string $model, string $metric): JsonResponse
    {
        $m = $this->find($model, $metric);
        $user = $request->user();
        $snapshot = $this->store->snapshot($m);
        $ref = $this->store->ref($m);
        $versions = MetricVersion::with('author:id,name')->where('semantic_model_id', $m->semantic_model_id)->where('metric_key', $m->key)->orderByDesc('version')->get();
        $history = $user->hasPermission('audit.view') || $user->hasPermission('governance.view')
            ? AuditLog::with('user:id,name')->where('resource_type', 'metric')->where('resource_id', $ref)->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get()
                ->map(fn ($a) => ['action' => $a->action, 'by' => $a->user?->name, 'at' => $a->created_at, 'meta' => $a->meta])
            : [];

        return response()->json(['data' => $this->summary($m) + [
            'expression' => $m->expression,
            'formula' => $this->definitions->formula($snapshot),
            'measures' => $snapshot['measures'],
            'base' => $snapshot['base'],
            'synonyms' => $m->synonyms,
            'target' => $m->target,
            'higher_is_better' => $m->higher_is_better,
            'available_measures' => array_keys($this->store->measuresOf($m->semanticModel)),
            'usage' => $this->usage->index($user)[$ref] ?? ['dashboards' => [], 'reports' => [], 'alerts' => [], 'hidden' => 0, 'total' => 0],
            'versions' => $versions->map(fn ($v) => ['version' => $v->version, 'summary' => $v->summary, 'by' => $v->author?->name, 'at' => $v->created_at,
                'definition' => $v->definition, 'formula' => $this->definitions->formula($v->definition), 'definition_hash' => $v->definition_hash]),
            'history' => $history,
            'allowed' => $this->allowed($m, $user),
        ]]);
    }

    public function update(Request $request, string $model, string $metric): JsonResponse
    {
        $data = $request->validate([
            'label' => 'sometimes|string|max:120', 'description' => 'sometimes|nullable|string|max:2000', 'expression' => 'sometimes|string|max:500',
            'format' => ['sometimes', Rule::in(['number', 'percent', 'currency', 'duration_days'])], 'target' => 'sometimes|nullable|numeric',
            'synonyms' => 'sometimes|array|max:20', 'synonyms.*' => 'string|max:60', 'is_kpi' => 'sometimes|boolean', 'higher_is_better' => 'sometimes|boolean',
            'owner' => 'sometimes|nullable|string|max:120', 'business_owner_id' => 'sometimes|nullable|uuid', 'data_owner_id' => 'sometimes|nullable|uuid',
        ]);
        $m = $this->store->update($this->find($model, $metric), $data, $request->user());

        return $this->show($request, $model, $m->key);
    }

    public function transition(Request $request, string $model, string $metric): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(MetricStore::ACTIONS)], 'note' => 'nullable|string|max:1000', 'replaced_by' => 'nullable|string|max:120']);
        $this->store->transition($this->find($model, $metric), $data['action'], $request->user(), $data['note'] ?? null, $data['replaced_by'] ?? null);

        return $this->show($request, $model, $metric);
    }

    public function restore(Request $request, string $model, string $metric, int $version): JsonResponse
    {
        $this->store->restore($this->find($model, $metric), $version, $request->user());

        return $this->show($request, $model, $metric);
    }

    /** People who can be named as owners. */
    public function people(): JsonResponse
    {
        return response()->json(['data' => User::where('status', 'active')->orderBy('name')->get(['id', 'name', 'title'])]);
    }

    private function find(string $model, string $metric): Metric
    {
        $semantic = SemanticModel::where('key', $model)->firstOrFail();

        return $semantic->metrics()->where('key', $metric)->with(['semanticModel', 'businessOwner:id,name', 'dataOwner:id,name', 'approver:id,name', 'certifier:id,name'])->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function summary(Metric $m): array
    {
        return [
            'ref' => $this->store->ref($m), 'key' => $m->key, 'label' => $m->label, 'description' => $m->description, 'format' => $m->format, 'is_kpi' => $m->is_kpi,
            'model' => ['key' => $m->semanticModel->key, 'name' => $m->semanticModel->name],
            'status' => $m->status, 'status_note' => $m->status_note, 'replaced_by' => $m->replaced_by, 'version' => $m->version, 'owner' => $m->owner,
            'business_owner' => $m->businessOwner?->only(['id', 'name']), 'data_owner' => $m->dataOwner?->only(['id', 'name']),
            'approved_by' => $m->relationLoaded('approver') ? $m->approver?->name : null, 'approved_at' => $m->approved_at,
            'certified_by' => $m->certifier?->name, 'certified_at' => $m->certified_at, 'updated_at' => $m->updated_at,
        ];
    }

    /** @param  array{total: int}|null  $usage */
    private function usageCount(?array $usage): int
    {
        return $usage['total'] ?? 0;
    }

    /**
     * What this person can do to this metric now, with the reason when they cannot.
     *
     * @return array<string, bool|string>
     */
    private function allowed(Metric $m, User $user): array
    {
        $manage = $user->hasPermission('semantic.manage');
        $certify = $user->hasPermission('metrics.certify');

        return [
            'edit' => $manage,
            'approve' => $manage && $m->status === 'proposed',
            'certify' => $certify && $m->status === 'approved' && $m->approved_by !== $user->id,
            'certify_blocked' => $certify && $m->status === 'approved' && $m->approved_by === $user->id ? 'You approved this definition, so someone else must certify it.' : '',
            'revoke' => $certify && $m->status === 'certified',
            'deprecate' => ($manage || $certify) && $m->status !== 'deprecated',
            'reinstate' => $manage && $m->status === 'deprecated',
            'restore' => $manage,
        ];
    }
}
