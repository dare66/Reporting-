<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Tests\SeededTestCase;

class DataPlatformTest extends SeededTestCase
{
    public function test_dataset_preview_masks_sensitive_columns_for_analysts(): void
    {
        $id = collect($this->as('analyst@emgs.demo')->getJson('/api/v1/datasets')->json('data'))->firstWhere('name', 'applications')['id'];
        $rows = $this->as('analyst@emgs.demo')->getJson("/api/v1/datasets/{$id}/preview")->assertOk()->json('rows');
        $this->assertSame('•••••', $rows[0]['applicant_ref']);
        $rows = $this->as('engineer@emgs.demo')->getJson("/api/v1/datasets/{$id}/preview")->json('rows');
        $this->assertStringStartsWith('AP', $rows[0]['applicant_ref']);
    }

    public function test_planned_connectors_are_honestly_unavailable(): void
    {
        $this->as('engineer@emgs.demo')->postJson('/api/v1/data-sources', ['connector_key' => 'oracle', 'name' => 'Legacy'])->assertStatus(422);
    }

    public function test_queries_and_ai_runs_are_audited(): void
    {
        $this->as('analyst@emgs.demo')->postJson('/api/v1/query', ['model' => 'revenue', 'metrics' => ['revenue']])->assertOk();
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'query.run')->whereNotNull('query_hash')->exists());

        $run = $this->as('analyst@emgs.demo')->postJson('/api/v1/ai/runs', ['question' => 'Revenue?', 'answer' => 'RM 1M', 'status' => 'succeeded',
            'evidence' => [['query_hash' => 'abc']], 'planner' => 'deterministic'])->assertCreated()->json('data');
        $this->as('analyst@emgs.demo')->getJson("/api/v1/ai/conversations/{$run['conversation_id']}")->assertOk()->assertJsonCount(2, 'data.messages');
        $this->as('ceo@emgs.demo')->getJson("/api/v1/ai/conversations/{$run['conversation_id']}")->assertNotFound();
        $this->as('analyst@emgs.demo')->getJson('/api/v1/governance/ai')->assertOk()->assertJsonPath('data.totals.grounded_share', 1);
    }

    public function test_dashboard_lifecycle_and_mobile_layout(): void
    {
        $d = $this->as('analyst@emgs.demo')->postJson('/api/v1/dashboards', ['title' => 'Mine', 'widgets' => [
            ['type' => 'kpi', 'query' => ['model' => 'revenue', 'metrics' => ['revenue'], 'time' => ['range' => 'last_30_days']]],
            ['type' => 'chart', 'query' => ['model' => 'applications', 'metrics' => ['total_applications'], 'time' => ['grain' => 'month', 'range' => 'last_12_months']], 'viz' => ['type' => 'line']],
        ]])->assertCreated()->json('data');
        $show = $this->as('analyst@emgs.demo')->getJson("/api/v1/dashboards/{$d['id']}")->assertOk()->json('data');
        $this->assertCount(2, $show['layouts']['mobile']);
        foreach ($show['widgets'] as $w) {
            $this->as('analyst@emgs.demo')->postJson("/api/v1/dashboards/{$d['id']}/widgets/{$w['id']}/data")->assertOk();
        }
        $this->as('ceo@emgs.demo')->getJson("/api/v1/dashboards/{$d['id']}")->assertNotFound(); // private by default
    }

    public function test_mentions_notify_department(): void
    {
        $dash = collect($this->as('analyst@emgs.demo')->getJson('/api/v1/dashboards')->json('data'))->first();
        $res = $this->as('analyst@emgs.demo')->postJson('/api/v1/comments', ['resource_type' => 'dashboard', 'resource_id' => $dash['id'], 'body' => '@Finance please validate this revenue anomaly.'])->assertCreated();
        $this->assertSame(1, $res->json('notified')); // the CFO sits in Finance
    }

    public function test_search_finds_metrics_by_synonym(): void
    {
        $res = $this->as('ceo@emgs.demo')->getJson('/api/v1/search?q=turnaround')->assertOk();
        $this->assertContains('decisions.avg_processing_days', collect($res->json('data'))->pluck('id')->all());
        $this->assertTrue($this->as('ceo@emgs.demo')->getJson('/api/v1/search?q=why did sla fall?')->json('ask_ai'));
    }
}
