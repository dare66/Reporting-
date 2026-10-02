<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\AiFeedback;
use App\Models\AiModel;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Prompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GovernanceController extends Controller
{
    public function auditLogs(Request $request): JsonResponse
    {
        $q = AuditLog::with('user:id,name,email')->latest('created_at');
        foreach (['action', 'decision', 'result', 'user_id', 'resource_type'] as $f) {
            if ($v = $request->query($f)) {
                $f === 'action' ? $q->where('action', 'like', $v.'%') : $q->where($f, $v);
            }
        }
        $page = $q->paginate(min(100, (int) $request->query('per_page', 50)));
        if (! $request->user()->hasPermission('query.explain')) {
            $page->getCollection()->each(fn ($l) => $l->query_sql = null);
        }

        return response()->json($page);
    }

    /** AI usage, cost, quality and grounding statistics. */
    public function ai(Request $request): JsonResponse
    {
        $since = now()->subDays((int) $request->query('days', 30));
        $runs = AiRun::where('created_at', '>=', $since);
        $byDay = (clone $runs)->selectRaw("date_trunc('day', created_at)::date AS day, count(*) AS runs, sum(tokens_in) AS tokens_in, sum(tokens_out) AS tokens_out, sum(cost_usd) AS cost, avg(latency_ms) AS latency")
            ->groupBy('day')->orderBy('day')->get();
        $total = (clone $runs)->count();
        $grounded = (clone $runs)->whereRaw('jsonb_array_length(evidence) > 0')->count();
        $fb = AiFeedback::whereIn('run_id', (clone $runs)->select('id'));

        return response()->json(['data' => [
            'totals' => [
                'runs' => $total,
                'succeeded' => (clone $runs)->where('status', 'succeeded')->count(),
                'failed' => (clone $runs)->where('status', 'failed')->count(),
                'refused' => (clone $runs)->where('status', 'refused')->count(),
                'tokens_in' => (int) (clone $runs)->sum('tokens_in'),
                'tokens_out' => (int) (clone $runs)->sum('tokens_out'),
                'cost_usd' => round((float) (clone $runs)->sum('cost_usd'), 4),
                'avg_latency_ms' => (int) (clone $runs)->avg('latency_ms'),
                'grounded_share' => $total ? round($grounded / $total, 4) : null,
                'feedback_positive' => (clone $fb)->where('rating', 1)->count(),
                'feedback_negative' => (clone $fb)->where('rating', -1)->count(),
            ],
            'by_day' => $byDay,
            'by_planner' => (clone $runs)->select('planner', DB::raw('count(*) as runs'))->groupBy('planner')->get(),
            'by_intent' => (clone $runs)->select('intent', DB::raw('count(*) as runs'))->groupBy('intent')->orderByDesc('runs')->get(),
            'recent' => (clone $runs)->with('conversation:id,title')->latest()->limit(25)->get(['id', 'question', 'intent', 'status', 'planner', 'model', 'tokens_in', 'tokens_out', 'cost_usd', 'latency_ms', 'created_at', 'conversation_id', 'evidence']),
            'models' => AiModel::orderBy('purpose')->get(),
            'agents' => AiAgent::orderBy('stage')->get(),
            'prompts' => Prompt::orderBy('key')->orderByDesc('version')->get(),
        ]]);
    }

    public function run(string $id): JsonResponse
    {
        return response()->json(['data' => AiRun::with('conversation.messages')->findOrFail($id)]);
    }

    public function dataQuality(): JsonResponse
    {
        $datasets = Dataset::with('fields', 'dataSource:id,name,status,last_sync_at')->get();

        return response()->json(['data' => $datasets->map(function ($d) {
            $issues = $d->profile['issues'] ?? [];
            $staleHours = $d->freshness_at ? now()->diffInHours($d->freshness_at, true) : null;
            if ($staleHours !== null && $staleHours > 48) {
                $issues[] = ['severity' => 'warning', 'field' => null, 'message' => 'Latest record is '.round($staleHours / 24).' days old.'];
            }
            foreach ($d->fields as $f) {
                if (($f->profile['outliers_4sd'] ?? 0) > 0) {
                    $issues[] = ['severity' => 'info', 'field' => $f->name, 'message' => "{$f->profile['outliers_4sd']} values beyond 4σ."];
                }
            }
            $score = max(0, 100 - 25 * count(array_filter($issues, fn ($i) => $i['severity'] === 'critical')) - 8 * count(array_filter($issues, fn ($i) => $i['severity'] === 'warning')) - 2 * count(array_filter($issues, fn ($i) => $i['severity'] === 'info')));

            return ['id' => $d->id, 'label' => $d->label, 'table' => $d->physical_table, 'row_count' => $d->row_count, 'freshness_at' => $d->freshness_at,
                'profiled_at' => $d->profile['profiled_at'] ?? null, 'source' => $d->dataSource?->only(['name', 'status', 'last_sync_at']),
                'sensitive_fields' => $d->fields->where('is_sensitive', true)->pluck('name')->values(), 'quality_score' => $score, 'issues' => $issues];
        })]);
    }
}
