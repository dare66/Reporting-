<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\AppNotification;
use Illuminate\Support\Facades\Http;
use Tests\SeededTestCase;

class ReportingTest extends SeededTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['ai.test/*' => Http::response([
            'method' => 'holt_linear', 'points' => [['period' => '2026-11-01', 'value' => 100, 'lower' => 90, 'upper' => 110]], 'diagnostics' => [],
        ])]);
    }

    private function generate(): array
    {
        return $this->as('ceo@emgs.demo')->postJson('/api/v1/reports/generate', ['template' => 'ceo', 'range' => 'last_month'])->assertCreated()->json('data');
    }

    public function test_generates_every_section_from_template_with_evidence(): void
    {
        $report = $this->generate();
        $types = collect($report['sections'])->pluck('type')->all();
        $this->assertSame(['summary', 'kpis', 'chart', 'chart', 'breakdown', 'anomalies', 'root_cause', 'forecast', 'risks'], $types);
        foreach ($report['sections'] as $s) {
            $this->assertArrayNotHasKey('error', $s['content'], $s['title'].': '.($s['content']['error'] ?? ''));
        }
        $this->assertNotEmpty($report['sections'][0]['content']['paragraphs']);
        $this->assertSame(1, $report['current_version']);
    }

    public function test_exports_all_formats(): void
    {
        $id = $this->generate()['id'];
        foreach (['pdf' => '%PDF', 'pptx' => 'PK', 'xlsx' => 'PK', 'csv' => 'section', 'html' => '<!doctype'] as $format => $magic) {
            $export = $this->as('ceo@emgs.demo')->postJson("/api/v1/reports/{$id}/exports", ['format' => $format])->assertStatus(202)->json('data');
            $status = $this->as('ceo@emgs.demo')->getJson("/api/v1/report-exports/{$export['id']}")->json('data');
            $this->assertSame('ready', $status['status'], "{$format}: ".($status['error'] ?? ''));
            $body = $this->as('ceo@emgs.demo')->get("/api/v1/report-exports/{$export['id']}/download")->assertOk()->streamedContent();
            $this->assertStringStartsWith($magic, $body, $format);
        }
    }

    public function test_versioning_publish_restore_compare(): void
    {
        $r = $this->generate();
        $summary = collect($r['sections'])->firstWhere('type', 'summary');
        $this->as('ceo@emgs.demo')->patchJson("/api/v1/reports/{$r['id']}/sections/{$summary['id']}", ['content' => ['paragraphs' => ['Edited narrative.'], 'cards' => ['tamper']]])->assertOk()
            ->assertJsonPath('data.content.paragraphs.0', 'Edited narrative.')->assertJsonMissingPath('data.content.cards');
        $this->as('ceo@emgs.demo')->postJson("/api/v1/reports/{$r['id']}/publish")->assertOk()->assertJsonPath('version', 2);

        $diff = $this->as('ceo@emgs.demo')->getJson("/api/v1/reports/{$r['id']}/compare?a=1&b=2")->assertOk()->json('data.changes');
        $this->assertSame('Executive Summary', $diff[0]['section']);

        $restored = $this->as('ceo@emgs.demo')->postJson("/api/v1/reports/{$r['id']}/versions/1/restore")->assertOk()->json('data');
        $this->assertSame('draft', $restored['status']);
        $this->assertSame(3, $restored['current_version']);
        $this->assertNotSame('Edited narrative.', collect($restored['sections'])->firstWhere('type', 'summary')['content']['paragraphs'][0]);
    }

    public function test_drafts_are_private_until_published(): void
    {
        $id = $this->generate()['id'];
        $this->as('analyst@emgs.demo')->getJson("/api/v1/reports/{$id}")->assertNotFound();
        $this->as('ceo@emgs.demo')->postJson("/api/v1/reports/{$id}/publish")->assertOk();
        $this->as('analyst@emgs.demo')->getJson("/api/v1/reports/{$id}")->assertOk()->assertJsonPath('data.can_edit', false);
    }

    public function test_alert_breach_sends_intelligent_notification_once(): void
    {
        $this->as('coo@emgs.demo')->postJson('/api/v1/alert-rules', [
            'name' => 'SLA below 99%', 'model' => 'decisions', 'metric_key' => 'sla_compliance', 'operator' => 'lt', 'threshold' => 0.99,
            'window' => 'last_30_days', 'channels' => ['in_app'], 'created_via' => 'conversation',
        ])->assertCreated();
        $rule = AlertRule::withoutGlobalScopes()->where('name', 'SLA below 99%')->first();

        $first = $this->as('coo@emgs.demo')->postJson("/api/v1/alert-rules/{$rule->id}/evaluate")->assertOk()->json('data');
        $this->assertSame(['breached', true], [$first['state'], $first['fired']]);
        $second = $this->as('coo@emgs.demo')->postJson("/api/v1/alert-rules/{$rule->id}/evaluate")->json('data');
        $this->assertFalse($second['fired']);

        $note = AppNotification::withoutGlobalScopes()->where('type', 'alert')->latest()->first();
        $this->assertStringContainsString('Processing SLA', $note->body);
        $this->assertStringContainsString('/investigate?metric=decisions.sla_compliance', $note->link);
    }

    public function test_alert_rules_carry_their_metric_format(): void
    {
        $this->as('coo@emgs.demo')->postJson('/api/v1/alert-rules', [
            'name' => 'Revenue under RM 1M', 'model' => 'revenue', 'metric_key' => 'revenue', 'operator' => 'lt', 'threshold' => 1000000,
            'window' => 'last_30_days', 'channels' => ['in_app'],
        ])->assertCreated();

        $rules = collect($this->as('coo@emgs.demo')->getJson('/api/v1/alert-rules')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['label' => 'Revenue', 'format' => 'currency'], $rules['Revenue under RM 1M']['metric']);
    }
}
