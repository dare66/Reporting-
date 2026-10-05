<?php

namespace App\Http\Controllers\Api;

use App\Domain\Actions\ActionEngine;
use App\Domain\Actions\DuplicateAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Data\OutboundGuard;
use App\Http\Controllers\Controller;
use App\Models\ActionDestination;
use App\Models\ActionRequest;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Actions people (or the AI analyst, as them) propose; approvals; the incidents
 * approved actions open; and the destinations administrators set up.
 */
class ActionController extends Controller
{
    public function __construct(private readonly ActionEngine $engine, private readonly AuditLogger $audit) {}

    /** Approvers see every action; everyone else sees the ones they proposed. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = ActionRequest::with(['requester:id,name', 'decider:id,name', 'destination:id,name,kind', 'incident:id,action_id,number,status'])->latest();
        if (! $user->hasPermission('actions.approve')) {
            $q->where('requested_by', $user->id);
        }
        if ($status = $request->query('status')) {
            $q->whereIn('status', explode(',', (string) $status));
        }

        return response()->json([
            'data' => $q->limit(200)->get()->map(fn (ActionRequest $a) => $this->present($a, $user))->values(),
            'awaiting' => $user->hasPermission('actions.approve') ? ActionRequest::where('status', 'proposed')->count() : 0,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->visible($request, $id), $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(ActionEngine::KINDS)],
            'title' => 'required|string|max:250',
            'summary' => 'nullable|string|max:5000',
            'payload' => 'sometimes|array',
            'evidence' => 'sometimes|array',
            'destination_id' => 'nullable|uuid',
            'source' => 'sometimes|in:manual,ai',
            'source_ref' => 'nullable|string|max:120',
        ]);
        $user = $request->user();
        $action = $this->attempt(fn () => $this->engine->propose($data, $user, $user->organisation_id, $data['source'] ?? 'manual', $data['source_ref'] ?? null));

        return response()->json(['data' => $this->present($action->fresh(['requester', 'destination']) ?? $action, $user)], 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $note = $request->validate(['note' => 'nullable|string|max:1000'])['note'] ?? null;
        $action = $this->attempt(fn () => $this->engine->approve($this->visible($request, $id), $request->user(), $note));

        return response()->json(['data' => $this->present($action, $request->user())]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $note = $request->validate(['note' => 'required|string|max:1000'])['note'];
        $action = $this->attempt(fn () => $this->engine->reject($this->visible($request, $id), $request->user(), $note));

        return response()->json(['data' => $this->present($action, $request->user())]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $action = $this->attempt(fn () => $this->engine->cancel($this->visible($request, $id), $request->user()));

        return response()->json(['data' => $this->present($action, $request->user())]);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $action = $this->attempt(fn () => $this->engine->retry($this->visible($request, $id), $request->user()));

        return response()->json(['data' => $this->present($action, $request->user())]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $q = Incident::with(['assignee:id,name', 'action:id,source,requested_by,decided_by'])->orderByDesc('number');
        if ($status = $request->query('status')) {
            $q->whereIn('status', explode(',', (string) $status));
        }

        return response()->json(['data' => $q->limit(200)->get()]);
    }

    public function updateIncident(Request $request, string $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);
        $user = $request->user();
        abort_unless($user->hasPermission('actions.approve') || $incident->assignee_id === $user->id, 403, 'Only approvers or the assignee can update this incident.');
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Incident::STATUSES)],
            'severity' => ['sometimes', Rule::in(Incident::SEVERITIES)],
            'assignee_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')->where('organisation_id', $user->organisation_id)],
        ]);
        if (isset($data['status'])) {
            $data['resolved_at'] = $data['status'] === 'resolved' ? now() : null;
        }
        $incident->update($data);
        $this->audit->record('incident.updated', ['resource_type' => 'incident', 'resource_id' => $incident->id], array_diff_key($data, ['resolved_at' => 1]));

        return response()->json(['data' => $incident->fresh('assignee:id,name')]);
    }

    /** Active people, for choosing who an incident is assigned to or who is notified. */
    public function people(): JsonResponse
    {
        return response()->json(['data' => User::where('status', 'active')->orderBy('name')->get(['id', 'name', 'title'])]);
    }

    /** Destinations without their secrets, for choosing one when proposing an action. */
    public function destinations(): JsonResponse
    {
        return response()->json(['data' => ActionDestination::orderBy('name')->get(['id', 'name', 'kind', 'is_active'])]);
    }

    public function storeDestination(Request $request, OutboundGuard $guard): JsonResponse
    {
        $data = $this->destinationRules($request);
        $this->checkConfig($data['kind'], $data['config'], $guard);
        $destination = ActionDestination::create($data + ['created_by' => $request->user()->id]);
        $this->audit->record('action_destination.created', ['resource_type' => 'action_destination', 'resource_id' => $destination->id], ['kind' => $destination->kind]);

        return response()->json(['data' => $destination->only(['id', 'name', 'kind', 'is_active'])], 201);
    }

    public function updateDestination(Request $request, string $id, OutboundGuard $guard): JsonResponse
    {
        $destination = ActionDestination::findOrFail($id);
        $data = $request->validate(['name' => 'sometimes|string|max:120', 'is_active' => 'sometimes|boolean', 'config' => 'sometimes|array']);
        if (isset($data['config'])) {
            $this->checkConfig($destination->kind, $data['config'], $guard);
        }
        $destination->update($data);
        $this->audit->record('action_destination.updated', ['resource_type' => 'action_destination', 'resource_id' => $destination->id], ['fields' => array_keys($data)]);

        return response()->json(['data' => $destination->only(['id', 'name', 'kind', 'is_active'])]);
    }

    public function deleteDestination(string $id): JsonResponse
    {
        $destination = ActionDestination::findOrFail($id);
        abort_if(ActionRequest::withoutGlobalScope('project')->where('destination_id', $id)->where('status', 'proposed')->exists(), 409,
            'Actions waiting for approval use this destination. Decide them first, or turn the destination off.');
        $destination->delete();
        $this->audit->record('action_destination.deleted', ['resource_type' => 'action_destination', 'resource_id' => $id], ['kind' => $destination->kind]);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @return array{name: string, kind: string, config: array<string, mixed>, is_active?: bool} */
    private function destinationRules(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'kind' => ['required', Rule::in(ActionDestination::KINDS)],
            'config' => 'required|array',
            'is_active' => 'sometimes|boolean',
        ]);
    }

    /** @param  array<string, mixed>  $config */
    private function checkConfig(string $kind, array $config, OutboundGuard $guard): void
    {
        try {
            if ($kind === 'email') {
                $emails = (array) ($config['recipients'] ?? []);
                abort_if($emails === [] || array_filter($emails, fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL)) !== [], 422, 'List one or more valid email addresses.');
            } else {
                $guard->assertUrlAllowed((string) ($config['url'] ?? ''));
                abort_if(isset($config['headers']) && ! is_array($config['headers']), 422, 'Headers must be a list of name and value pairs.');
            }
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    private function visible(Request $request, string $id): ActionRequest
    {
        $user = $request->user();
        $action = ActionRequest::with(['requester:id,name', 'decider:id,name', 'destination:id,name,kind', 'incident'])->findOrFail($id);
        abort_unless($user->hasPermission('actions.approve') || $action->requested_by === $user->id, 404);

        return $action;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function attempt(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (DuplicateAction $e) {
            abort(409, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function present(ActionRequest $a, User $user): array
    {
        $approver = $user->hasPermission('actions.approve');
        $outbound = in_array($a->kind, ActionEngine::OUTBOUND, true);

        return [
            'id' => $a->id, 'kind' => $a->kind, 'title' => $a->title, 'summary' => $a->summary, 'status' => $a->status,
            'payload' => $a->payload, 'evidence' => $a->evidence, 'source' => $a->source, 'source_ref' => $a->source_ref,
            'requested_by' => $a->requester?->only(['id', 'name']), 'decided_by' => $a->decider?->only(['id', 'name']),
            'decided_at' => $a->decided_at?->toIso8601String(), 'decision_note' => $a->decision_note,
            'executed_at' => $a->executed_at?->toIso8601String(), 'verified_at' => $a->verified_at?->toIso8601String(),
            'result' => $a->result, 'error' => $a->error, 'attempts' => $a->attempts,
            'destination' => $a->destination?->only(['id', 'name', 'kind']),
            'incident' => $a->incident ? ['id' => $a->incident->id, 'reference' => $a->incident->reference, 'status' => $a->incident->status] : null,
            'created_at' => $a->created_at?->toIso8601String(),
            'needs_second_person' => $outbound,
            'can_approve' => $approver && $a->status === 'proposed' && ! ($outbound && $a->requested_by === $user->id),
            'can_cancel' => $a->status === 'proposed' && $a->requested_by === $user->id,
            'can_retry' => $approver && $a->status === 'failed',
        ];
    }
}
