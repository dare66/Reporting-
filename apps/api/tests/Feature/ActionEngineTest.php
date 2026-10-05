<?php

namespace Tests\Feature;

use App\Domain\Data\OutboundGuard;
use App\Models\ActionRequest;
use App\Models\AlertRule;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\SeededTestCase;

/**
 * Propose → approve → run → verify → audit. Nothing runs without someone
 * allowed to approve it, and messages that leave the platform need a second person.
 */
class ActionEngineTest extends SeededTestCase
{
    private function id(string $email): string
    {
        return User::withoutGlobalScopes()->where('email', $email)->value('id');
    }

    public function test_an_incident_runs_only_once_approved_and_is_audited(): void
    {
        $this->as('viewer@emgs.demo')->getJson('/api/v1/actions')->assertForbidden();
        $proposed = $this->as('analyst@emgs.demo')->postJson('/api/v1/actions', [
            'kind' => 'incident', 'title' => 'SLA fell to 84%', 'summary' => 'Processing SLA dropped 7 points.',
            'payload' => ['severity' => 'high', 'metric_ref' => 'decisions.sla_compliance', 'assignee_id' => $this->id('coo@emgs.demo')],
            'evidence' => ['drivers' => [['member' => 'Document backlog']]], 'source' => 'ai',
        ])->assertCreated()->json('data');
        $this->assertSame('proposed', $proposed['status']);
        $this->assertFalse($proposed['can_approve'], 'the analyst cannot approve');
        $this->assertSame(0, Incident::withoutGlobalScopes()->count(), 'nothing happens on proposal');

        // Approvers are told; the analyst cannot approve.
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $this->id('ceo@emgs.demo'))
            ->where('link', '/actions?id='.$proposed['id'])->exists());
        $this->as('analyst@emgs.demo')->postJson("/api/v1/actions/{$proposed['id']}/approve")->assertForbidden();
        $this->as('analyst@emgs.demo')->getJson('/api/v1/actions')->assertOk()->assertJsonPath('data.0.id', $proposed['id']);

        $done = $this->as('ceo@emgs.demo')->postJson("/api/v1/actions/{$proposed['id']}/approve", ['note' => 'Go'])->assertOk()->json('data');
        $this->assertSame('done', $done['status'], (string) $done['error']);
        $this->assertNotNull($done['verified_at']);
        $this->assertMatchesRegularExpression('/^INC-\d{4}$/', $done['incident']['reference']);
        $incident = Incident::withoutGlobalScopes()->findOrFail($done['incident']['id']);
        $this->assertSame(['high', 'decisions.sla_compliance', $this->id('coo@emgs.demo')], [$incident->severity, $incident->metric_ref, $incident->assignee_id]);
        $this->assertSame('Document backlog', $incident->evidence['drivers'][0]['member']);
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $this->id('coo@emgs.demo'))->where('title', 'like', 'INC-%')->exists());

        $this->as('ceo@emgs.demo')->postJson("/api/v1/actions/{$proposed['id']}/approve")->assertStatus(422);
        // The same issue again points to the open incident instead of opening a second one.
        $this->as('analyst@emgs.demo')->postJson('/api/v1/actions', ['kind' => 'incident', 'title' => 'Again', 'payload' => ['metric_ref' => 'decisions.sla_compliance']])
            ->assertStatus(409)->assertJsonPath('message', "{$done['incident']['reference']} is already open for this metric: “SLA fell to 84%”.");
        $trail = AuditLog::withoutGlobalScopes()->where('resource_id', $proposed['id'])->orderBy('created_at')->pluck('action')->all();
        $this->assertSame(['action.proposed', 'action.approved', 'action.executed'], $trail);

        // The assignee works the incident.
        $this->as('coo@emgs.demo')->patchJson("/api/v1/incidents/{$incident->id}", ['status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->as('analyst@emgs.demo')->patchJson("/api/v1/incidents/{$incident->id}", ['status' => 'open'])->assertForbidden();
    }

    public function test_rejecting_and_withdrawing(): void
    {
        $a = $this->as('analyst@emgs.demo')->postJson('/api/v1/actions', ['kind' => 'incident', 'title' => 'One'])->json('data.id');
        $this->as('ceo@emgs.demo')->postJson("/api/v1/actions/{$a}/reject")->assertStatus(422); // a reason is required
        $this->as('ceo@emgs.demo')->postJson("/api/v1/actions/{$a}/reject", ['note' => 'Already known'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(0, Incident::withoutGlobalScopes()->count());

        $b = $this->as('analyst@emgs.demo')->postJson('/api/v1/actions', ['kind' => 'incident', 'title' => 'Two'])->json('data.id');
        $this->as('ceo@emgs.demo')->postJson("/api/v1/actions/{$b}/cancel")->assertStatus(422);
        $this->as('analyst@emgs.demo')->postJson("/api/v1/actions/{$b}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->as('analyst@emgs.demo')->postJson('/api/v1/actions', ['kind' => 'incident', 'title' => 'x', 'payload' => ['severity' => 'apocalyptic']])->assertStatus(422);
        $this->as('analyst@emgs.demo')->postJson('/api/v1/actions', ['kind' => 'delete_database', 'title' => 'x'])->assertStatus(422);
    }

    public function test_outbound_actions_need_a_second_person_and_a_2xx(): void
    {
        $this->app->instance(OutboundGuard::class, new OutboundGuard(fn () => ['93.184.216.34']));
        Http::fake(['hooks.example.org/*' => Http::sequence()->push(['ok' => false], 500)->push(['key' => 'OPS-42'], 201)]);

        $this->as('manager.asia@emgs.demo')->postJson('/api/v1/action-destinations', ['name' => 'x', 'kind' => 'webhook', 'config' => ['url' => 'https://hooks.example.org/a']])->assertForbidden();
        $this->as('admin@emgs.demo')->postJson('/api/v1/action-destinations', ['name' => 'Bad', 'kind' => 'webhook', 'config' => ['url' => 'file:///etc/passwd']])->assertStatus(422);
        $dest = $this->as('admin@emgs.demo')->postJson('/api/v1/action-destinations', [
            'name' => 'Ops tickets', 'kind' => 'webhook', 'config' => ['url' => 'https://hooks.example.org/tickets', 'headers' => ['X-Token' => 's3cret']],
        ])->assertCreated()->json('data');
        $listed = $this->as('analyst@emgs.demo')->getJson('/api/v1/action-destinations')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('config', $listed, 'secrets never leave the server');

        $this->as('manager.asia@emgs.demo')->postJson('/api/v1/actions', ['kind' => 'webhook', 'title' => 'Open a ticket'])->assertStatus(422); // no destination
        $id = $this->as('manager.asia@emgs.demo')->postJson('/api/v1/actions', ['kind' => 'webhook', 'title' => 'Open a ticket', 'destination_id' => $dest['id']])
            ->assertCreated()->assertJsonPath('data.can_approve', false)->json('data.id');
        $this->as('manager.asia@emgs.demo')->postJson("/api/v1/actions/{$id}/approve")->assertStatus(422);

        // The remote end fails: the action is failed, not done, and can be run again.
        $failed = $this->as('ceo@emgs.demo')->postJson("/api/v1/actions/{$id}/approve")->assertOk()->json('data');
        $this->assertSame('failed', $failed['status']);
        $this->assertStringContainsString('HTTP 500', $failed['error']);
        $this->assertNull($failed['verified_at']);
        $done = $this->as('ceo@emgs.demo')->postJson("/api/v1/actions/{$id}/retry")->assertOk()->json('data');
        $this->assertSame(['done', 201, 2], [$done['status'], $done['result']['status'], $done['attempts']]);
        Http::assertSent(fn ($r) => $r->url() === 'https://hooks.example.org/tickets' && $r['title'] === 'Open a ticket' && $r->hasHeader('X-Token', 's3cret'));
    }

    public function test_an_alert_proposes_an_incident_once(): void
    {
        $this->as('coo@emgs.demo')->postJson('/api/v1/alert-rules', [
            'name' => 'SLA below 99%', 'model' => 'decisions', 'metric_key' => 'sla_compliance', 'operator' => 'lt', 'threshold' => 0.99,
            'window' => 'last_30_days', 'actions' => [['kind' => 'incident', 'severity' => 'critical']],
        ])->assertCreated();
        $rule = AlertRule::withoutGlobalScopes()->where('name', 'SLA below 99%')->firstOrFail();

        $this->as('coo@emgs.demo')->postJson("/api/v1/alert-rules/{$rule->id}/evaluate")->assertOk()->assertJsonPath('data.fired', true);
        $proposals = ActionRequest::withoutGlobalScopes()->where('source', 'alert')->where('source_ref', $rule->id)->get();
        $this->assertCount(1, $proposals);
        $this->assertSame(['proposed', 'critical', 'decisions.sla_compliance'], [$proposals[0]->status, $proposals[0]->payload['severity'], $proposals[0]->payload['metric_ref']]);
        $this->assertSame($rule->project_id, $proposals[0]->project_id);

        // It recovers and fires again while the first proposal is still open: no second proposal.
        AlertRule::withoutGlobalScopes()->whereKey($rule->id)->update(['last_state' => 'ok']);
        $this->as('coo@emgs.demo')->postJson("/api/v1/alert-rules/{$rule->id}/evaluate")->assertJsonPath('data.fired', true);
        $this->assertSame(1, ActionRequest::withoutGlobalScopes()->where('source_ref', $rule->id)->count());
    }
}
