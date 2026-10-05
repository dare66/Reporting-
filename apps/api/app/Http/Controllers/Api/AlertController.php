<?php

namespace App\Http\Controllers\Api;

use App\Domain\Alerts\AlertEvaluator;
use App\Domain\Audit\AuditLogger;
use App\Domain\Semantic\CatalogRepository;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Metric;
use App\Models\SemanticModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Rules with their metric's label and display format, so values render in the metric's own units. */
    public function rules(): JsonResponse
    {
        $rules = AlertRule::with('semanticModel:id,key,name')->withCount('alerts')->orderBy('name')->get();
        $metrics = Metric::whereIn('semantic_model_id', $rules->pluck('semantic_model_id')->unique())
            ->get(['semantic_model_id', 'key', 'label', 'format'])
            ->keyBy(fn (Metric $m) => $m->semantic_model_id.'.'.$m->key);
        foreach ($rules as $rule) {
            $metric = $metrics->get($rule->semantic_model_id.'.'.$rule->metric_key);
            $rule->setAttribute('metric', $metric ? ['label' => $metric->label, 'format' => $metric->format] : null);
        }

        return response()->json(['data' => $rules]);
    }

    public function store(Request $request, CatalogRepository $catalogs): JsonResponse
    {
        $data = $this->validated($request);
        $model = SemanticModel::where('key', $data['model'])->firstOrFail();
        $catalogs->get($model->key)->metric($data['metric_key']); // validates the metric exists
        $rule = AlertRule::create(collect($data)->except('model')->all() + ['semantic_model_id' => $model->id, 'owner_id' => $request->user()->id,
            'recipients' => $data['recipients'] ?? [$request->user()->id]]);
        $this->audit->record('alert_rule.created', ['resource_type' => 'alert_rule', 'resource_id' => $rule->id], ['via' => $rule->created_via]);

        return response()->json(['data' => $rule->load('semanticModel:id,key,name')], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $rule = AlertRule::findOrFail($id);
        abort_unless($rule->owner_id === $request->user()->id || $request->user()->hasPermission('admin.org'), 403, 'Only the owner can change this alert.');
        $rule->update($request->validate(['name' => 'sometimes|string|max:160', 'operator' => 'sometimes|in:lt,lte,gt,gte,change_pct_gt,change_pct_lt',
            'threshold' => 'sometimes|numeric', 'window' => 'sometimes|string', 'frequency_minutes' => 'sometimes|integer|min:5|max:10080',
            'channels' => 'sometimes|array', 'recipients' => 'sometimes|array', 'is_active' => 'sometimes|boolean', 'filters' => 'sometimes|array',
            ...self::ACTION_RULES]));

        return response()->json(['data' => $rule]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $rule = AlertRule::findOrFail($id);
        abort_unless($rule->owner_id === $request->user()->id || $request->user()->hasPermission('admin.org'), 403, 'Only the owner can delete this alert.');
        $rule->delete();

        return response()->json(null, 204);
    }

    public function evaluate(string $id, AlertEvaluator $evaluator): JsonResponse
    {
        return response()->json(['data' => $evaluator->evaluate(AlertRule::findOrFail($id))]);
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json(['data' => Alert::with('rule:id,name,metric_key')->latest('fired_at')->limit(100)->get()]);
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $alert = Alert::findOrFail($id);
        $alert->update(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()->id]);

        return response()->json(['data' => $alert]);
    }

    /** When a rule fires it can propose an incident; someone with `actions.approve` still decides. */
    private const ACTION_RULES = [
        'actions' => 'sometimes|array|max:1', 'actions.*.kind' => 'required|in:incident', 'actions.*.severity' => 'sometimes|in:low,medium,high,critical',
    ];

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:160', 'model' => 'required|string', 'metric_key' => 'required|string',
            'operator' => 'required|in:lt,lte,gt,gte,change_pct_gt,change_pct_lt', 'threshold' => 'required|numeric',
            'window' => 'nullable|in:today,last_7_days,last_30_days,this_month,this_week', 'filters' => 'array',
            'frequency_minutes' => 'nullable|integer|min:5|max:10080', 'channels' => 'array', 'channels.*' => 'in:in_app,email,push',
            'recipients' => 'array', 'recipients.*' => 'uuid', 'created_via' => 'nullable|in:form,conversation',
            ...self::ACTION_RULES,
        ]);
    }
}
