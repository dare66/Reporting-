<?php

namespace Tests\Feature;

use App\Domain\Semantic\SemanticModelImporter;
use App\Models\Metric;
use App\Models\MetricVersion;
use App\Models\SemanticModel;
use App\Support\Tenancy\TenantScopeBypass;
use Tests\SeededTestCase;

/** The metric store: lifecycle, four-eyes certification, versions and rollback, usage, and governed re-imports. */
class MetricStoreTest extends SeededTestCase
{
    private const ENGINEER = 'engineer@emgs.demo';

    private const ADMIN = 'admin@emgs.demo';

    private const URL = '/api/v1/metric-store/decisions/approval_rate';

    private function metric(string $key = 'approval_rate'): Metric
    {
        return TenantScopeBypass::run(fn () => SemanticModel::where('key', 'decisions')->firstOrFail()->metrics()->where('key', $key)->firstOrFail());
    }

    public function test_the_store_lists_governed_metrics_with_owners_counts_and_usage(): void
    {
        $res = $this->as('analyst@emgs.demo')->getJson('/api/v1/metric-store')->assertOk();
        $this->assertSame(['proposed' => 0, 'approved' => 16, 'certified' => 7, 'deprecated' => 0], $res->json('counts'));
        $rate = collect($res->json('data'))->firstWhere('ref', 'decisions.approval_rate');
        $this->assertSame('certified', $rate['status']);
        $this->assertSame('Aisha Tan', $rate['business_owner']['name']);
        $this->assertSame('Daniel Lim', $rate['data_owner']['name']);
        $this->assertGreaterThan(0, $rate['usage']);

        $this->assertCount(7, $this->as('analyst@emgs.demo')->getJson('/api/v1/metric-store?status=certified')->json('data'));
        $this->as('viewer@emgs.demo')->getJson('/api/v1/metric-store')->assertForbidden();

        $detail = $this->as('analyst@emgs.demo')->getJson(self::URL)->assertOk()->json('data');
        $this->assertStringContainsString('÷', $detail['formula']);
        $this->assertSame(2, $detail['version']);
        $this->assertCount(2, $detail['versions']);
        $this->assertNotEmpty($detail['usage']['dashboards']);
        $this->assertFalse($detail['allowed']['edit']);
    }

    public function test_renaming_keeps_certification_but_changing_the_calculation_lapses_it(): void
    {
        $this->as(self::ENGINEER)->patchJson(self::URL, ['label' => 'Approval rate (decided)', 'description' => 'Share of decided applications that were approved.'])
            ->assertOk()->assertJsonPath('data.status', 'certified')->assertJsonPath('data.version', 3);

        $this->as(self::ENGINEER)->patchJson(self::URL, ['expression' => 'approved_count / nonsense'])->assertStatus(422);
        $changed = $this->as(self::ENGINEER)->patchJson(self::URL, ['expression' => 'approved_count / (approved_count + rejected_count)'])->assertOk()->json('data');
        $this->assertSame('proposed', $changed['status']);
        $this->assertSame(4, $changed['version']);
        $this->assertStringContainsString('needs approval again', $changed['status_note']);
        $this->assertNull($changed['certified_by']);
        $this->assertStringContainsString('Approval lapsed', $changed['versions'][0]['summary']);

        // The governed numbers follow the new definition straight away.
        $this->as(self::ADMIN)->postJson('/api/v1/query', ['model' => 'decisions', 'metrics' => ['approval_rate'], 'time' => ['range' => 'last_90_days']])->assertOk();
    }

    public function test_certification_needs_a_second_person(): void
    {
        $this->as(self::ENGINEER)->patchJson(self::URL, ['expression' => 'approved_count / (approved_count + rejected_count)'])->assertOk();

        $this->as('analyst@emgs.demo')->postJson(self::URL.'/transitions', ['action' => 'approve'])->assertForbidden();
        $this->as(self::ENGINEER)->postJson(self::URL.'/transitions', ['action' => 'certify'])->assertStatus(422);
        $approved = $this->as(self::ENGINEER)->postJson(self::URL.'/transitions', ['action' => 'approve'])->assertOk()->json('data');
        $this->assertSame('approved', $approved['status']);
        $this->assertSame('You approved this definition, so someone else must certify it.', $approved['allowed']['certify_blocked']);

        $this->as(self::ENGINEER)->postJson(self::URL.'/transitions', ['action' => 'certify'])->assertStatus(422)
            ->assertJsonPath('error.message', fn ($m) => str_contains($m, 'second person'));
        $certified = $this->as(self::ADMIN)->postJson(self::URL.'/transitions', ['action' => 'certify'])->assertOk()->json('data');
        $this->assertSame('certified', $certified['status']);
        $this->assertSame('Sarah Wong', $certified['certified_by']);

        $this->as(self::ADMIN)->postJson(self::URL.'/transitions', ['action' => 'revoke'])->assertStatus(422);
        $this->as(self::ADMIN)->postJson(self::URL.'/transitions', ['action' => 'revoke', 'note' => 'Under review after the policy change.'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $history = $this->as(self::ADMIN)->getJson(self::URL)->json('data.history');
        $this->assertSame(['metric.revoked', 'metric.certified', 'metric.approved', 'metric.updated'], array_column($history, 'action'));
    }

    public function test_deprecation_names_a_replacement_and_can_be_reversed(): void
    {
        $this->as(self::ENGINEER)->postJson(self::URL.'/transitions', ['action' => 'deprecate'])->assertStatus(422);
        $this->as(self::ENGINEER)->postJson(self::URL.'/transitions', ['action' => 'deprecate', 'note' => 'Replaced.', 'replaced_by' => 'no_such_metric'])->assertStatus(422);
        $this->as(self::ENGINEER)->postJson(self::URL.'/transitions', ['action' => 'deprecate', 'note' => 'Use the rejection rate view.', 'replaced_by' => 'rejection_rate'])
            ->assertOk()->assertJsonPath('data.status', 'deprecated')->assertJsonPath('data.replaced_by', 'rejection_rate');
        $this->as(self::ENGINEER)->postJson(self::URL.'/transitions', ['action' => 'reinstate'])->assertOk()->assertJsonPath('data.status', 'proposed');
    }

    public function test_an_earlier_version_can_be_restored_as_a_new_version(): void
    {
        $this->as(self::ENGINEER)->patchJson(self::URL, ['expression' => 'approved_count / (approved_count + rejected_count)'])->assertOk();
        $restored = $this->as(self::ENGINEER)->postJson(self::URL.'/versions/2/restore')->assertOk()->json('data');
        $this->assertSame('approved_count / decided_count', $restored['expression']);
        $this->assertSame(4, $restored['version']);
        $this->assertSame('Restored version 2.', $restored['versions'][0]['summary']);
        $this->assertSame('proposed', $restored['status'], 'a restored calculation is reviewed again');
        $this->as(self::ENGINEER)->postJson(self::URL.'/versions/99/restore')->assertNotFound();
    }

    public function test_reimporting_a_model_keeps_governance_unless_the_calculation_changes(): void
    {
        $def = json_decode(file_get_contents(database_path('seeders/semantic/decisions.json')), true);
        $import = fn (array $d) => TenantScopeBypass::run(fn () => app(SemanticModelImporter::class)->import($this->metric()->semanticModel->organisation_id, $d));
        $versions = fn () => TenantScopeBypass::run(fn () => MetricVersion::where('metric_key', 'approval_rate')->count());

        $import($def);
        $this->assertSame('certified', $this->metric()->status, 'an identical import changes nothing');
        $this->assertSame(2, $versions());

        foreach ($def['metrics'] as &$m) {
            if ($m['key'] === 'approval_rate') {
                $m['expression'] = 'approved_count / (approved_count + rejected_count)';
            }
        }
        unset($m);
        $import($def);
        $this->assertSame('proposed', $this->metric()->status);
        $this->assertSame(3, $versions());
        $this->assertSame('certified', $this->metric('rejection_rate')->status, 'other metrics keep their certification');
    }
}
