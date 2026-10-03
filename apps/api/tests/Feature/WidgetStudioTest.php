<?php

namespace Tests\Feature;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Support\Tenancy\TenantScopeBypass;
use Tests\SeededTestCase;

/**
 * Widget Studio and dashboard filters, end to end on PostgreSQL: quick
 * functions, ranking and measure filters, member pickers and filter scoping.
 */
class WidgetStudioTest extends SeededTestCase
{
    private const ASIA = ['CN', 'VN', 'TH', 'JP', 'KR', 'ID'];

    private function executiveOverview(): Dashboard
    {
        return TenantScopeBypass::run(fn () => Dashboard::where('title', 'Executive Overview')->firstOrFail());
    }

    private function sourceMarkets(Dashboard $dashboard): string
    {
        return TenantScopeBypass::run(fn () => DashboardWidget::where('dashboard_id', $dashboard->id)->where('title', 'Source markets')->value('id'));
    }

    /** @param array<string, mixed> $query */
    private function runQuery(string $email, array $query): array
    {
        return $this->as($email)->postJson('/api/v1/query', ['model' => 'applications'] + $query)->assertOk()->json();
    }

    public function test_share_of_total_is_a_share_of_everything_not_of_the_rows_returned(): void
    {
        $q = [
            'metrics' => ['total_applications'], 'dimensions' => ['country'], 'time' => ['range' => 'last_12_months'],
            'calculations' => [['fn' => 'percent_of_total', 'metric' => 'total_applications']],
            'sort' => [['key' => 'total_applications', 'dir' => 'desc']],
        ];
        $all = $this->runQuery('analyst@northstar.demo', $q);
        $shares = array_column($all['rows'], 'total_applications__percent_of_total');
        $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-4); // rows carry values rounded to 6 places
        $this->assertSame('percent', collect($all['columns'])->firstWhere('key', 'total_applications__percent_of_total')['format']);

        $top3 = $this->runQuery('analyst@northstar.demo', $q + ['limit' => 3])['rows'];
        $this->assertCount(3, $top3);
        $this->assertSame($shares[0], $top3[0]['total_applications__percent_of_total']);
        $this->assertLessThan(1.0, array_sum(array_column($top3, 'total_applications__percent_of_total')));
    }

    public function test_running_total_and_change_follow_the_monthly_series(): void
    {
        $rows = $this->runQuery('analyst@northstar.demo', [
            'metrics' => ['total_applications'], 'time' => ['grain' => 'month', 'range' => 'last_6_months'],
            'calculations' => [['fn' => 'running_sum', 'metric' => 'total_applications'], ['fn' => 'difference', 'metric' => 'total_applications']],
        ])['rows'];

        $this->assertGreaterThan(2, count($rows));
        $sum = 0;
        foreach ($rows as $i => $r) {
            $sum += $r['total_applications'];
            $this->assertEquals($sum, $r['total_applications__running_sum']);
            $expected = $i === 0 ? null : $r['total_applications'] - $rows[$i - 1]['total_applications'];
            $this->assertEquals($expected, $r['total_applications__difference']);
        }
    }

    public function test_quick_functions_refuse_to_add_up_ratios(): void
    {
        $this->as('analyst@northstar.demo')->postJson('/api/v1/query', [
            'model' => 'applications', 'metrics' => ['high_risk_share'], 'dimensions' => ['region'],
            'calculations' => [['fn' => 'percent_of_total', 'metric' => 'high_risk_share']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_query');
    }

    public function test_top_n_is_ranked_inside_the_users_row_level_security(): void
    {
        $base = ['metrics' => ['total_applications'], 'dimensions' => ['country_code'], 'time' => ['range' => 'last_12_months']];
        $top = ['filters' => [['dimension' => 'country_code', 'op' => 'top', 'value' => ['n' => 3, 'metric' => 'total_applications']]]];
        $byVolume = ['sort' => [['key' => 'total_applications', 'dir' => 'desc']]];

        $ceo = $this->runQuery('ceo@northstar.demo', $base + $top + $byVolume);
        $ceoAll = $this->runQuery('ceo@northstar.demo', $base + $byVolume + ['limit' => 3]);
        $this->assertSame($ceoAll['rows'], $ceo['rows']);
        // Evidence records the question as asked; the members it resolved to are bound in the SQL.
        $this->assertSame('top', $ceo['meta']['query']['filters'][0]['op']);
        $this->assertStringContainsString('IN (?, ?, ?)', $ceo['meta']['sql'] ?? $this->runQuery('analyst@northstar.demo', $base + $top)['meta']['sql']);

        $codes = array_column($this->runQuery('manager.asia@northstar.demo', $base + $top)['rows'], 'country_code');
        $this->assertCount(3, $codes);
        $this->assertSame([], array_diff($codes, self::ASIA));
    }

    public function test_measure_filters_keep_only_the_groups_that_qualify(): void
    {
        $q = ['model' => 'decisions', 'metrics' => ['sla_compliance'], 'dimensions' => ['institution'], 'time' => ['range' => 'last_90_days']];
        $all = collect($this->as('analyst@northstar.demo')->postJson('/api/v1/query', $q)->json('rows'));
        $threshold = $all->pluck('sla_compliance')->median();

        $below = $this->as('analyst@northstar.demo')
            ->postJson('/api/v1/query', $q + ['having' => [['metric' => 'sla_compliance', 'op' => 'lt', 'value' => $threshold]]])
            ->assertOk()->json('rows');

        $this->assertNotEmpty($below);
        $this->assertEqualsCanonicalizing($all->where('sla_compliance', '<', $threshold)->pluck('institution')->all(), array_column($below, 'institution'));
    }

    public function test_member_pickers_respect_row_level_security_search_and_sensitivity(): void
    {
        $url = '/api/v1/semantic-models/applications/dimensions/country_code/members';
        $this->assertContains('IN', $this->as('ceo@northstar.demo')->getJson($url)->assertOk()->json('data'));

        $asia = $this->as('manager.asia@northstar.demo')->getJson($url)->assertOk()->json('data');
        $this->assertNotEmpty($asia);
        $this->assertSame([], array_diff($asia, self::ASIA));

        $search = $this->as('ceo@northstar.demo')->getJson($url.'?search=n')->assertOk()->json('data');
        $this->assertNotEmpty($search);
        $this->assertSame($search, array_values(array_filter($search, fn ($c) => stripos($c, 'n') !== false)));

        $this->as('analyst@northstar.demo')->getJson('/api/v1/semantic-models/applications/dimensions/applicant_ref/members')->assertForbidden();
        $this->as('analyst@northstar.demo')->getJson('/api/v1/semantic-models/applications/dimensions/nope/members')->assertStatus(422);
    }

    public function test_dashboard_filters_reach_only_widgets_whose_model_has_the_dimension(): void
    {
        $dashboard = $this->executiveOverview();
        [$funnel, $revenue] = TenantScopeBypass::run(fn () => [
            DashboardWidget::where('dashboard_id', $dashboard->id)->where('title', 'Application funnel')->firstOrFail(),
            DashboardWidget::where('dashboard_id', $dashboard->id)->where('title', 'Revenue by stream')->firstOrFail(),
        ]);
        $data = fn (DashboardWidget $w, array $filters) => $this->as('ceo@northstar.demo')
            ->postJson("/api/v1/dashboards/{$dashboard->id}/widgets/{$w->id}/data", ['filters' => $filters])->assertOk()->json('data.rows');

        $stages = array_column($data($funnel, []), 'stage');
        $onlyFirst = [['dimension' => 'stage', 'op' => 'in', 'value' => [$stages[0]], 'label' => 'Stage']];

        $this->assertSame([$stages[0]], array_column($data($funnel, $onlyFirst), 'stage'));
        $this->assertSame($data($revenue, []), $data($revenue, $onlyFirst), 'revenue has no stage dimension, so the filter does not apply');
        $this->assertSame($stages, array_column($data($funnel, [['disabled' => true] + $onlyFirst[0]]), 'stage'), 'paused filters are ignored');

        // A ranking filter also needs its metric: "top country by applications" cannot rank revenue widgets.
        $topCountry = [['dimension' => 'country', 'op' => 'top', 'value' => ['n' => 1, 'metric' => 'total_applications']]];
        $this->assertCount(1, $this->as('ceo@northstar.demo')->postJson("/api/v1/dashboards/{$dashboard->id}/widgets/{$this->sourceMarkets($dashboard)}/data", ['filters' => $topCountry])->assertOk()->json('data.rows'));
        $this->assertSame($data($revenue, []), $data($revenue, $topCountry));

        $this->as('viewer@northstar.demo')->postJson("/api/v1/dashboards/{$dashboard->id}/widgets/{$funnel->id}/data", ['filters' => [['dimension' => 'stage', 'op' => 'drop']]])
            ->assertStatus(422);
    }

    public function test_viewers_get_filterable_dimensions_and_members_without_query_rights(): void
    {
        $dashboard = $this->executiveOverview();
        $dims = collect($this->as('viewer@northstar.demo')->getJson("/api/v1/dashboards/{$dashboard->id}")->assertOk()->json('data.filter_dimensions'))->keyBy('key');

        $this->assertEqualsCanonicalizing(['applications', 'decisions', 'revenue'], $dims['country']['models']);
        $this->assertSame(['applications'], $dims['stage']['models']);
        $this->assertArrayNotHasKey('applicant_ref', $dims->all(), 'sensitive dimensions are not offered without data.sensitive');
        $this->assertArrayNotHasKey('submitted_on', $dims->all(), 'time is filtered by the date range, not a dimension filter');

        $metrics = collect($this->as('viewer@northstar.demo')->getJson("/api/v1/dashboards/{$dashboard->id}")->json('data.filter_metrics'))->keyBy('key');
        $this->assertSame(['key' => 'total_applications', 'label' => 'Applications', 'models' => ['applications']], $metrics['total_applications']);

        $url = "/api/v1/dashboards/{$dashboard->id}/filter-members?dimension=payment_type";
        $this->assertNotEmpty($this->as('ceo@northstar.demo')->getJson($url)->assertOk()->json('data'));
        // The demo viewer has no market attribute, so row-level security fails closed for the picker too.
        $this->assertSame([], $this->as('viewer@northstar.demo')->getJson($url)->assertOk()->json('data'));
        $this->as('viewer@northstar.demo')->getJson("/api/v1/dashboards/{$dashboard->id}/filter-members?dimension=visa_status_unknown")->assertStatus(422);
    }

    public function test_editors_save_default_filters_with_labels_and_paused_state(): void
    {
        $dashboard = $this->executiveOverview();
        $filters = [
            ['dimension' => 'country', 'op' => 'not_in', 'value' => ['China'], 'label' => 'Country'],
            ['dimension' => 'institution', 'op' => 'top', 'value' => ['n' => '5', 'metric' => 'total_applications'], 'disabled' => true],
        ];
        $saved = $this->as('analyst@northstar.demo')->patchJson("/api/v1/dashboards/{$dashboard->id}", ['filters' => $filters])->assertOk()->json('data.filters');

        $this->assertSame([
            ['dimension' => 'country', 'op' => 'not_in', 'value' => ['China'], 'label' => 'Country'],
            ['dimension' => 'institution', 'op' => 'top', 'value' => ['n' => 5, 'metric' => 'total_applications'], 'disabled' => true],
        ], $saved);
        $this->as('viewer@northstar.demo')->patchJson("/api/v1/dashboards/{$dashboard->id}", ['filters' => []])->assertForbidden();
    }
}
