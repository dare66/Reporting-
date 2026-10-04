<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Domain\AutoBi\DataTrust;
use App\Domain\Trust\DriftMonitor;
use App\Http\Controllers\Controller;
use App\Models\Dataset;
use App\Models\DatasetSnapshot;
use App\Models\DriftEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Data Trust Center: how far each dataset can be trusted, how that has moved, and what changed in its shape. */
class TrustController extends Controller
{
    public function __construct(private readonly DataTrust $trust, private readonly DriftMonitor $monitor, private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $datasets = Dataset::with(['dataSource:id,name,connector_key,last_sync_at', 'fields'])->orderBy('label')->get();
        $snapshots = DatasetSnapshot::whereIn('dataset_id', $datasets->pluck('id'))->whereNotNull('trust_score')
            ->orderByDesc('taken_at')->orderByDesc('id')->get(['id', 'dataset_id', 'trust_score', 'taken_at'])->groupBy('dataset_id');
        $open = DriftEvent::where('status', 'open')->selectRaw('dataset_id, severity, COUNT(*) AS n')->groupBy('dataset_id', 'severity')->get()->groupBy('dataset_id');

        $rows = $datasets->map(function (Dataset $d) use ($snapshots, $open) {
            $history = $snapshots[$d->id] ?? collect();
            $drift = ($open[$d->id] ?? collect())->pluck('n', 'severity');

            // A dataset with no scored snapshot yet (e.g. only the baseline from an upgrade) is scored now.
            $score = $history->first()->trust_score ?? $this->trust->assess($d)['score'];

            return [
                'id' => $d->id, 'label' => $d->label, 'source' => $d->dataSource?->name, 'connector' => $d->dataSource?->connector_key,
                'rows' => (int) $d->row_count, 'score' => $score, 'previous_score' => $history->get(1)?->trust_score,
                'history' => $history->take(12)->reverse()->pluck('trust_score')->values(),
                'checked_at' => $history->first()->taken_at ?? ($d->profile['profiled_at'] ?? null),
                'open_drift' => ['critical' => (int) ($drift['critical'] ?? 0), 'warning' => (int) ($drift['warning'] ?? 0), 'info' => (int) ($drift['info'] ?? 0)],
            ];
        })->values();

        return response()->json(['data' => $rows, 'summary' => [
            'datasets' => $rows->count(),
            'average' => $rows->isEmpty() ? null : round((float) $rows->avg('score'), 1),
            'attention' => $rows->filter(fn ($r) => $r['score'] < 75 || $r['open_drift']['critical'] > 0)->count(),
            'open_critical' => $rows->sum(fn ($r) => $r['open_drift']['critical']),
        ]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $dataset = Dataset::with(['fields', 'dataSource:id,name,connector_key,last_sync_at'])->findOrFail($id);
        $user = $request->user();
        $events = DriftEvent::with('acknowledger:id,name')->where('dataset_id', $id)->orderByDesc('detected_at')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => [
            'id' => $dataset->id, 'label' => $dataset->label, 'table' => "{$dataset->physical_schema}.{$dataset->physical_table}",
            'source' => $dataset->dataSource?->only(['id', 'name', 'connector_key', 'last_sync_at']), 'rows' => (int) $dataset->row_count,
            'trust' => $this->trust->assess($dataset),
            'history' => DatasetSnapshot::where('dataset_id', $id)->orderByDesc('taken_at')->orderByDesc('id')->limit(30)->get(['id', 'trigger', 'row_count', 'trust_score', 'taken_at'])->reverse()->values(),
            'drift' => $events->map(fn (DriftEvent $e) => $e->only(['id', 'kind', 'column', 'severity', 'message', 'before', 'after', 'status', 'detected_at', 'acknowledged_at'])
                + ['acknowledged_by' => $e->acknowledger?->name, 'impact' => $e->column !== null || $e->kind === 'row_count_dropped'
                    ? $this->monitor->impact($dataset, $e->kind === 'column_renamed' ? ($e->before['name'] ?? $e->column) : $e->column, $user) : null]),
            'can_manage' => $user->hasPermission('data.manage'),
        ]]);
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $event = DriftEvent::findOrFail($id);
        abort_if($event->status === 'acknowledged', 422, 'This change was already acknowledged.');
        $event->update(['status' => 'acknowledged', 'acknowledged_by' => $request->user()->id, 'acknowledged_at' => now()]);
        $this->audit->record('data.drift_acknowledged', ['resource_type' => 'dataset', 'resource_id' => $event->dataset_id], ['kind' => $event->kind, 'column' => $event->column]);

        return response()->json(['data' => $event->fresh()]);
    }
}
