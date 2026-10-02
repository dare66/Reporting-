<?php

namespace App\Http\Controllers\Api;

use App\Domain\Alerts\AlertEvaluator;
use App\Domain\Audit\AuditLogger;
use App\Domain\Semantic\CatalogRepository;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\SemanticModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function rules(): JsonResponse
    {
        return response()->json(['data' => AlertRule::with('semanticModel:id,key,name')->withCount('alerts')->orderBy('name')->get()]);
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
            'channels' => 'sometimes|array', 'recipients' => 'sometimes|array', 'is_active' => 'sometimes|boolean', 'filters' => 'sometimes|array']));

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

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:160', 'model' => 'required|string', 'metric_key' => 'required|string',
            'operator' => 'required|in:lt,lte,gt,gte,change_pct_gt,change_pct_lt', 'threshold' => 'required|numeric',
            'window' => 'nullable|in:today,last_7_days,last_30_days,this_month,this_week', 'filters' => 'array',
            'frequency_minutes' => 'nullable|integer|min:5|max:10080', 'channels' => 'array', 'channels.*' => 'in:in_app,email,push',
            'recipients' => 'array', 'recipients.*' => 'uuid', 'created_via' => 'nullable|in:form,conversation',
        ]);
    }
}
