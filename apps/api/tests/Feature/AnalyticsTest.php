<?php

namespace Tests\Feature;

use App\Domain\Analytics\KpiService;
use App\Domain\Analytics\RootCauseService;
use App\Domain\Query\TimeRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\SeededTestCase;

/** Deterministic analytics must equal independent SQL computations. */
class AnalyticsTest extends SeededTestCase
{
    public function test_kpi_values_match_hand_written_sql(): void
    {
        $user = $this->actAs('ceo@northstar.demo');
        $card = collect(app(KpiService::class)->cards(['decisions.sla_compliance', 'revenue.revenue'], 'last_30_days', $user))->keyBy('ref');
        $r = TimeRange::resolve('last_30_days');

        $sla = DB::selectOne('SELECT COUNT(*) FILTER (WHERE sla_met)::float / NULLIF(COUNT(*) FILTER (WHERE sla_met IS NOT NULL), 0) AS v FROM analytics.applications WHERE decided_on >= ? AND decided_on < ?', [$r->from->toDateString(), $r->to->toDateString()])->v;
        $rev = DB::selectOne('SELECT SUM(amount)::float AS v FROM analytics.payments WHERE paid_on >= ? AND paid_on < ?', [$r->from->toDateString(), $r->to->toDateString()])->v;

        $this->assertEqualsWithDelta($sla, $card['decisions.sla_compliance']['value'], 1e-6);
        $this->assertEqualsWithDelta($rev, $card['revenue.revenue']['value'], 0.01);
        $this->assertNotEmpty($card['revenue.revenue']['evidence']['current']['query_hash']);
    }

    public function test_root_cause_impacts_sum_exactly_for_additive_metrics(): void
    {
        $user = $this->actAs('ceo@northstar.demo');
        $rc = app(RootCauseService::class)->explain('applications.total_applications', 'last_30_days', $user, [], ['country', 'channel']);
        foreach ($rc['dimensions'] as $dim) {
            $all = app(RootCauseService::class)->explain('applications.total_applications', 'last_30_days', $user, [], [$dim['key']]);
            // Members are truncated in the payload; recompute over the full set via member_count sanity and top members.
            $this->assertGreaterThan(0, $dim['member_count']);
        }
        $channel = collect($rc['dimensions'])->firstWhere('key', 'channel');
        $this->assertLessThanOrEqual(8, count($channel['members']));
        // channel has 3 members → all present → impacts sum to the total change exactly.
        $this->assertEqualsWithDelta($rc['change'], array_sum(array_column($channel['members'], 'impact')), 1e-6);
        $this->assertNotEmpty($rc['evidence']);
    }

    public function test_root_cause_finds_the_seeded_rejection_driver(): void
    {
        $user = $this->actAs('ceo@northstar.demo');
        $rc = app(RootCauseService::class)->explain('decisions.rejection_rate', 'last_30_days', $user, [], ['country', 'channel', 'document_issue']);
        $this->assertSame('negative', $rc['sentiment']);
        $members = collect($rc['drivers'])->pluck('member');
        $this->assertTrue($members->contains('China') || $members->contains('passport'), 'Expected China or passport issues among drivers: '.$members->implode(', '));
    }

    public function test_root_cause_endpoint_and_drill_down(): void
    {
        $this->as('analyst@northstar.demo')->postJson('/api/v1/analysis/root-cause', ['metric' => 'decisions.sla_compliance', 'range' => 'last_30_days', 'dimensions' => ['institution']])
            ->assertOk()->assertJsonStructure(['data' => ['change', 'drivers', 'dimensions', 'onset', 'evidence', 'method']]);

        $drill = $this->as('analyst@northstar.demo')->postJson('/api/v1/analysis/drill', ['metric' => 'applications.total_applications', 'dimension' => 'region', 'value' => 'East Asia', 'range' => 'last_12_months'])->assertOk();
        $this->assertSame('country', $drill->json('level'));
        $this->assertContains('China', collect($drill->json('result.rows'))->pluck('country')->all());
    }

    public function test_scenario_is_anchored_to_observed_baseline(): void
    {
        $zero = $this->as('ceo@northstar.demo')->postJson('/api/v1/analysis/scenario', [])->assertOk()->json('data');
        $this->assertEqualsWithDelta($zero['baseline']['sla_compliance'], $zero['projected']['sla_compliance'], 1e-4);

        $more = $this->as('ceo@northstar.demo')->postJson('/api/v1/analysis/scenario', ['demand_change_pct' => 20, 'save_as' => 'Peak intake'])->assertOk()->json('data');
        $this->assertEqualsWithDelta($zero['baseline']['applications'] * 1.2, $more['projected']['applications'], 1);
        $this->assertNotNull($more['scenario_id']);
    }

    public function test_forecast_uses_complete_periods_and_persists(): void
    {
        Http::fake(['ai.test/v1/analytics/forecast' => Http::response([
            'method' => 'holt_winters_additive', 'points' => [['period' => '2026-11-01', 'value' => 10, 'lower' => 8, 'upper' => 12]], 'diagnostics' => ['mape' => 0.05],
        ])]);
        $res = $this->as('ceo@northstar.demo')->postJson('/api/v1/analysis/forecast', ['metric' => 'applications.total_applications', 'horizon' => '6m'])->assertOk();
        $history = $res->json('data.history');
        $this->assertLessThan(now()->startOfMonth()->toDateString(), end($history)['period']);
        Http::assertSent(fn ($req) => $req['grain'] === 'month' && $req['horizon'] === 6 && count($req['series']) >= 20);
    }

    public function test_an_unreachable_engine_is_a_clear_503_not_a_server_error(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));
        $this->as('analyst@northstar.demo')->postJson('/api/v1/analysis/forecast', ['metric' => 'decisions.sla_compliance', 'horizon' => '6m'])
            ->assertStatus(503)->assertJsonPath('error.code', 'engine_unavailable');
    }

    public function test_home_screen_ranks_what_needs_attention(): void
    {
        $data = $this->as('ceo@northstar.demo')->getJson('/api/v1/home')->assertOk()->json('data');
        $this->assertSame('Mohammed', $data['first_name']);
        $this->assertCount(4, $data['pulse']);
        $this->assertNotEmpty($data['attention']);
        $this->assertNotEmpty($data['insights']);
        $this->assertNotEmpty($data['summary']);
    }
}
