<?php

namespace App\Domain\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Data\OutboundGuard;
use App\Domain\Notifications\Notifier;
use App\Models\ActionDestination;
use App\Models\ActionRequest;
use App\Models\Incident;
use App\Models\User;
use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The action engine: propose → approve → run → verify → audit.
 *
 * Nothing runs on proposal. A person holding `actions.approve` decides, and
 * actions that leave the platform (webhook, Slack, Teams, email) need a second
 * person: whoever proposed one cannot approve it. Every step is audited, and a
 * run counts as done only once its effect is confirmed (the incident exists,
 * the notifications were stored, the remote end answered 2xx, the mailer
 * accepted the message).
 */
class ActionEngine
{
    public const KINDS = ['incident', 'notify', 'webhook', 'slack', 'teams', 'email'];

    /** Kinds that send data outside the platform, and so need a second person to approve. */
    public const OUTBOUND = ['webhook', 'slack', 'teams', 'email'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly OutboundGuard $guard,
    ) {}

    /**
     * @param  array{kind: string, title: string, summary?: ?string, payload?: array<string, mixed>, evidence?: array<string, mixed>, destination_id?: ?string, project_id?: string}  $data
     * @param  'manual'|'ai'|'alert'  $source
     */
    public function propose(array $data, ?User $requester, string $organisationId, string $source = 'manual', ?string $sourceRef = null): ActionRequest
    {
        $kind = $data['kind'];
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown action kind {$kind}.");
        }
        $payload = $this->validatePayload($kind, $data['payload'] ?? [], $organisationId);
        if ($kind === 'incident' && isset($payload['metric_ref'])) {
            $this->assertNotOpen($payload['metric_ref'], $organisationId);
        }
        $destination = null;
        if (in_array($kind, self::OUTBOUND, true)) {
            $id = $data['destination_id'] ?? null;
            $destination = is_string($id) && Str::isUuid($id) ? ActionDestination::where('organisation_id', $organisationId)->whereKey($id)->first() : null;
            if (! $destination || $destination->kind !== $kind || ! $destination->is_active) {
                throw new InvalidArgumentException('Choose an active '.$kind.' destination for this action.');
            }
        }

        $action = ActionRequest::create(array_filter([
            'organisation_id' => $organisationId,
            'project_id' => $data['project_id'] ?? null,
            'kind' => $kind,
            'destination_id' => $destination?->id,
            'title' => Str::limit($data['title'], 250, '…'),
            'summary' => $data['summary'] ?? null,
            'payload' => $payload,
            'evidence' => $data['evidence'] ?? [],
            'source' => $source,
            'source_ref' => $sourceRef,
            'status' => 'proposed',
            'requested_by' => $requester?->id,
        ], fn ($v) => $v !== null));

        $this->audit->record('action.proposed', ['resource_type' => 'action', 'resource_id' => $action->id],
            ['kind' => $kind, 'source' => $source, 'source_ref' => $sourceRef], $requester?->id, $organisationId);
        $this->notifier->toPermission('actions.approve', $organisationId, [
            'type' => 'alert', 'severity' => 'warning',
            'title' => 'Approval needed: '.$action->title,
            'body' => ($requester ? $requester->name : 'An alert').' proposed '.$this->describe($action).'. Nothing happens until someone approves it.',
            'link' => '/actions?id='.$action->id, 'data' => ['action_id' => $action->id],
        ]);

        return $action;
    }

    /** Approves the action and runs it straight away. */
    public function approve(ActionRequest $action, User $approver, ?string $note = null): ActionRequest
    {
        $this->assertCanDecide($action, $approver);
        if (in_array($action->kind, self::OUTBOUND, true) && $action->requested_by === $approver->id) {
            throw new InvalidArgumentException('This action sends data outside AIXBI, so someone other than the person who proposed it must approve it.');
        }
        $action->update(['status' => 'approved', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note]);
        $this->audit->record('action.approved', ['resource_type' => 'action', 'resource_id' => $action->id], ['kind' => $action->kind, 'note' => $note], $approver->id, $action->organisation_id);

        return $this->run($action, $approver);
    }

    public function reject(ActionRequest $action, User $approver, string $note): ActionRequest
    {
        $this->assertCanDecide($action, $approver);
        $action->update(['status' => 'rejected', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note]);
        $this->audit->record('action.rejected', ['resource_type' => 'action', 'resource_id' => $action->id], ['note' => $note], $approver->id, $action->organisation_id);
        if ($action->requested_by) {
            $this->notifier->toUsers([$action->requested_by], $action->organisation_id, [
                'type' => 'alert', 'severity' => 'info', 'title' => 'Not approved: '.$action->title,
                'body' => "{$approver->name}: {$note}", 'link' => '/actions?id='.$action->id,
            ]);
        }

        return $action;
    }

    /** The person who proposed an action can withdraw it until it is decided. */
    public function cancel(ActionRequest $action, User $user): ActionRequest
    {
        if ($action->status !== 'proposed') {
            throw new InvalidArgumentException('Only a proposed action can be withdrawn.');
        }
        if ($action->requested_by !== $user->id) {
            throw new InvalidArgumentException('Only the person who proposed this action can withdraw it.');
        }
        $action->update(['status' => 'cancelled']);
        $this->audit->record('action.cancelled', ['resource_type' => 'action', 'resource_id' => $action->id], [], $user->id, $action->organisation_id);

        return $action;
    }

    /** Runs an approved action again after a failure (for example, the remote system was down). */
    public function retry(ActionRequest $action, User $approver): ActionRequest
    {
        if ($action->status !== 'failed') {
            throw new InvalidArgumentException('Only a failed action can be run again.');
        }
        if (! $approver->hasPermission('actions.approve')) {
            throw new InvalidArgumentException('You are not allowed to run actions.');
        }
        $this->audit->record('action.retried', ['resource_type' => 'action', 'resource_id' => $action->id], ['attempt' => $action->attempts + 1], $approver->id, $action->organisation_id);

        return $this->run($action, $approver);
    }

    private function run(ActionRequest $action, User $by): ActionRequest
    {
        $action->update(['status' => 'running', 'attempts' => $action->attempts + 1, 'error' => null]);
        try {
            $result = match ($action->kind) {
                'incident' => $this->openIncident($action, $by),
                'notify' => $this->notify($action),
                'webhook', 'slack', 'teams' => $this->post($action),
                'email' => $this->email($action),
                default => throw new InvalidArgumentException("Unknown action kind {$action->kind}."),
            };
            $action->update(['status' => 'done', 'executed_at' => now(), 'verified_at' => now(), 'result' => $result]);
            $this->audit->record('action.executed', ['resource_type' => 'action', 'resource_id' => $action->id], ['kind' => $action->kind, 'result' => $result], $by->id, $action->organisation_id);
        } catch (Throwable $e) {
            $message = Str::limit($e->getMessage(), 500);
            $action->update(['status' => 'failed', 'executed_at' => now(), 'error' => $message]);
            $this->audit->record('action.failed', ['resource_type' => 'action', 'resource_id' => $action->id], ['kind' => $action->kind, 'error' => $message], $by->id, $action->organisation_id);
        }

        return $action->fresh() ?? $action;
    }

    /** @return array<string, mixed> */
    private function openIncident(ActionRequest $action, User $by): array
    {
        $p = $action->payload ?? [];
        $incident = DB::transaction(function () use ($action, $p, $by) {
            // Numbers are per organisation; the lock (held until commit) keeps two approvals from taking the same one.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['incidents:'.$action->organisation_id]);
            $next = (int) DB::table('incidents')->where('organisation_id', $action->organisation_id)->max('number') + 1;

            return Incident::create([
                'organisation_id' => $action->organisation_id, 'project_id' => $action->project_id, 'number' => $next,
                'title' => $action->title, 'description' => $action->summary, 'severity' => $p['severity'] ?? 'medium',
                'metric_ref' => $p['metric_ref'] ?? null, 'evidence' => $action->evidence ?? [],
                'assignee_id' => $p['assignee_id'] ?? null, 'action_id' => $action->id, 'opened_by' => $action->requested_by ?? $by->id,
            ]);
        });
        // Verification: the incident is really there.
        if (! Incident::withoutGlobalScope('project')->whereKey($incident->id)->exists()) {
            throw new InvalidArgumentException('The incident could not be confirmed after it was created.');
        }
        $tell = array_values(array_unique(array_filter([$incident->assignee_id, $action->requested_by])));
        if ($tell) {
            $this->notifier->toUsers($tell, $action->organisation_id, [
                'type' => 'alert', 'severity' => $incident->severity === 'critical' || $incident->severity === 'high' ? 'critical' : 'warning',
                'title' => "{$incident->reference} opened: {$incident->title}", 'body' => (string) ($incident->description ?? ''),
                'link' => '/actions?incident='.$incident->id, 'data' => ['incident_id' => $incident->id],
            ]);
        }

        return ['incident_id' => $incident->id, 'reference' => $incident->reference];
    }

    /** @return array<string, mixed> */
    private function notify(ActionRequest $action): array
    {
        $p = $action->payload ?? [];
        $sent = $this->notifier->toUsers($p['user_ids'] ?? [], $action->organisation_id, [
            'type' => 'alert', 'severity' => 'info', 'title' => $action->title, 'body' => (string) ($action->summary ?? ''),
            'link' => $p['link'] ?? null, 'channels' => ['in_app', 'email'],
        ]);
        if ($sent === 0) {
            throw new InvalidArgumentException('None of the recipients is an active person in this organisation.');
        }

        return ['recipients' => $sent];
    }

    /** @return array<string, mixed> */
    private function post(ActionRequest $action): array
    {
        $destination = $this->destination($action);
        $config = $destination->config ?? [];
        $url = (string) ($config['url'] ?? '');
        $this->guard->assertUrlAllowed($url);
        $text = $action->title.($action->summary ? "\n\n".$action->summary : '');
        $body = match ($action->kind) {
            'slack' => ['text' => $text],
            'teams' => ['@type' => 'MessageCard', '@context' => 'https://schema.org/extensions', 'summary' => $action->title, 'title' => $action->title, 'text' => (string) ($action->summary ?? '')],
            default => [
                'id' => $action->id, 'title' => $action->title, 'summary' => $action->summary, 'payload' => $action->payload,
                'evidence' => $action->evidence, 'source' => $action->source, 'approved_by' => $action->decider?->name, 'sent_at' => now()->toIso8601String(),
            ],
        };
        $headers = array_filter((array) ($config['headers'] ?? []), fn ($v, $k) => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
        $response = Http::timeout(15)->acceptJson()->withoutRedirecting()->withHeaders($headers)->post($url, $body);
        // Verification: the receiving system must accept it.
        if (! $response->successful()) {
            throw new InvalidArgumentException("{$destination->name} answered HTTP {$response->status()}: ".Str::limit(trim($response->body()), 200));
        }

        return ['destination' => $destination->name, 'status' => $response->status(), 'response' => Str::limit(trim($response->body()), 500)];
    }

    /** @return array<string, mixed> */
    private function email(ActionRequest $action): array
    {
        $destination = $this->destination($action);
        $to = array_values(array_filter((array) ($destination->config['recipients'] ?? []), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        if ($to === []) {
            throw new InvalidArgumentException("{$destination->name} has no valid email addresses.");
        }
        Mail::raw((string) ($action->summary ?? $action->title), fn ($m) => $m->to($to)->subject($action->title));

        return ['destination' => $destination->name, 'recipients' => count($to), 'mailer' => config('mail.default')];
    }

    private function destination(ActionRequest $action): ActionDestination
    {
        // Read afresh: the request may have loaded only its name, and it may have been turned off since.
        $destination = ActionDestination::find($action->destination_id);
        if (! $destination || ! $destination->is_active) {
            throw new InvalidArgumentException('The destination for this action was removed or turned off.');
        }

        return $destination;
    }

    /** One incident per issue at a time: a second request points to the one already open or waiting. */
    private function assertNotOpen(string $metricRef, string $organisationId): void
    {
        $open = Incident::withoutGlobalScope('project')->where('organisation_id', $organisationId)
            ->where('metric_ref', $metricRef)->where('status', '!=', 'resolved')->orderByDesc('number')->first();
        if ($open) {
            throw new DuplicateAction("{$open->reference} is already {$open->status} for this metric: “{$open->title}”.");
        }
        $waiting = ActionRequest::withoutGlobalScope('project')->where('organisation_id', $organisationId)->where('kind', 'incident')
            ->whereIn('status', ['proposed', 'approved', 'running'])->where('payload->metric_ref', $metricRef)->first();
        if ($waiting) {
            throw new DuplicateAction("An incident for this metric is already waiting for approval: “{$waiting->title}”.");
        }
    }

    private function assertCanDecide(ActionRequest $action, User $approver): void
    {
        if ($action->status !== 'proposed') {
            throw new InvalidArgumentException("This action is already {$action->status}.");
        }
        if (! $approver->hasPermission('actions.approve')) {
            throw new InvalidArgumentException('You are not allowed to approve actions.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(string $kind, array $payload, string $organisationId): array
    {
        $inOrg = fn (array $ids) => TenantScopeBypass::run(fn () => User::where('organisation_id', $organisationId)->whereIn('id', $ids)->pluck('id')->all());
        if ($kind === 'incident') {
            $severity = $payload['severity'] ?? 'medium';
            if (! in_array($severity, Incident::SEVERITIES, true)) {
                throw new InvalidArgumentException('Severity must be low, medium, high or critical.');
            }
            $assignee = $payload['assignee_id'] ?? null;
            if ($assignee !== null && $inOrg([$assignee]) === []) {
                throw new InvalidArgumentException('The assignee is not in this organisation.');
            }

            return array_filter(['severity' => $severity, 'assignee_id' => $assignee, 'metric_ref' => $payload['metric_ref'] ?? null], fn ($v) => $v !== null);
        }
        if ($kind === 'notify') {
            $ids = $inOrg(array_values(array_filter((array) ($payload['user_ids'] ?? []), 'is_string')));
            if ($ids === []) {
                throw new InvalidArgumentException('Choose at least one person to notify.');
            }

            return ['user_ids' => $ids, 'link' => $payload['link'] ?? null];
        }

        return [];
    }

    private function describe(ActionRequest $action): string
    {
        return match ($action->kind) {
            'incident' => 'opening an incident',
            'notify' => 'notifying '.count($action->payload['user_ids'] ?? []).' people',
            default => 'sending a message to '.($action->destination->name ?? $action->kind),
        };
    }
}
