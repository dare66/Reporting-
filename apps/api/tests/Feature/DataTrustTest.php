<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\Organisation;
use App\Models\SemanticModel;
use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Data Trust Center and safe source deletion, on real uploads: a file is loaded,
 * modelled and used on a dashboard, then reloaded with a broken schema. Commits
 * for real (ingestion creates tables) and cleans up after itself.
 */
class DataTrustTest extends TestCase
{
    private const ENGINEER = 'engineer@emgs.demo';

    protected function setUp(): void
    {
        parent::setUp();
        if (! TenantScopeBypass::run(fn () => Organisation::where('slug', 'emgs')->exists())) {
            Artisan::call('migrate:fresh', ['--seed' => true]);
        }
    }

    protected function tearDown(): void
    {
        TenantScopeBypass::run(function () {
            Dashboard::where('title', 'Trust test')->get()->each->delete();
            $datasets = Dataset::where('name', 'like', 'ds_%trust_orders')->get();
            SemanticModel::whereIn('base_dataset_id', $datasets->pluck('id'))->get()->each->delete();
            foreach ($datasets as $d) {
                DB::statement('DROP TABLE IF EXISTS analytics."'.$d->physical_table.'"');
                $d->delete();
            }
            DataSource::where('name', 'Trust Orders (upload)')->delete();
        });
        parent::tearDown();
    }

    /** @param  callable(int): string  $row */
    private function upload(string $header, callable $row, int $rows): array
    {
        $csv = $header."\n";
        foreach (range(1, $rows) as $i) {
            $csv .= $row($i)."\n";
        }

        return $this->as(self::ENGINEER)->post('/api/v1/data/upload', ['file' => UploadedFile::fake()->createWithContent('Trust Orders.csv', $csv)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
    }

    public function test_a_broken_schema_is_detected_with_its_impact_and_the_owners_are_told(): void
    {
        $regions = ['North', 'South', 'East'];
        $first = $this->upload('order_date,region,product,amount,units', fn ($i) => sprintf('2026-%02d-%02d,%s,%s,%.2f,%d', ($i % 9) + 1, ($i % 27) + 1, $regions[$i % 3], ['Alpha', 'Beta'][$i % 2], 100 + $i * 3.5, $i % 7 + 1), 60);
        $datasetId = $first['dataset']['id'];
        $model = $this->as(self::ENGINEER)->postJson("/api/v1/datasets/{$datasetId}/semantic-model")->assertCreated()->json('data.key');
        $this->as('admin@emgs.demo')->postJson('/api/v1/dashboards', ['title' => 'Trust test', 'visibility' => 'organisation', 'widgets' => [
            ['type' => 'chart', 'title' => 'Amount by region', 'query' => ['model' => $model, 'metrics' => ['total_amount'], 'dimensions' => ['region']], 'viz' => ['type' => 'bar']],
        ]])->assertCreated();

        $baseline = $this->as(self::ENGINEER)->getJson("/api/v1/trust/datasets/{$datasetId}")->assertOk()->json('data');
        $this->assertSame([], $baseline['drift']);
        $this->assertNull(collect($baseline['trust']['parts'])->firstWhere('key', 'stability')['score'], 'stability needs two loads');

        // The same file arrives again, broken: amount is now text, units is gone, a column and a region are new, rows fell.
        $regions[] = 'West';
        $second = $this->upload('order_date,region,product,amount,channel', fn ($i) => sprintf('2026-%02d-%02d,%s,%s,%d pcs,%s', ($i % 9) + 1, ($i % 27) + 1, $regions[$i % 4], ['Alpha', 'Beta'][$i % 2], 100 + $i, ['web', 'agent'][$i % 2]), 30);
        $this->assertSame($datasetId, $second['dataset']['id'], 'a reload updates the same dataset');
        $this->assertNotContains('units', array_column($second['dataset']['fields'], 'name'), 'columns that are gone are not kept as fields');

        $trust = $this->as(self::ENGINEER)->getJson("/api/v1/trust/datasets/{$datasetId}")->assertOk()->json('data');
        $drift = collect($trust['drift'])->keyBy(fn ($e) => $e['kind'].':'.$e['column']);
        $amount = $drift['type_changed:amount'];
        $this->assertSame('critical', $amount['severity'], 'a breaking change to a column in use is critical');
        $this->assertSame('amount changed from decimal to string.', $amount['message']);
        $this->assertContains("{$model}.total_amount", array_column($amount['impact']['metrics'], 'ref'));
        $this->assertSame(['Trust test'], array_column($amount['impact']['dashboards'], 'title'));
        $this->assertSame('critical', $drift['column_removed:units']['severity']);
        $this->assertSame('info', $drift['column_added:channel']['severity']);
        $this->assertStringContainsString('West', $drift['new_values:region']['message']);
        $this->assertSame('warning', $drift['row_count_dropped:']['severity']);
        $stability = collect($trust['trust']['parts'])->firstWhere('key', 'stability');
        $this->assertLessThan(50, $stability['score']);
        $this->assertCount(2, $trust['history'], 'one snapshot per load');

        $note = TenantScopeBypass::run(fn () => AppNotification::where('type', 'data_quality')->where('user_id', $this->user(self::ENGINEER)->id)->latest()->first());
        $this->assertNotNull($note, 'the data team is told');
        $this->assertSame('critical', $note->severity);
        $this->assertSame("/trust?dataset={$datasetId}", $note->link);

        $list = $this->as('analyst@emgs.demo')->getJson('/api/v1/trust')->assertOk()->json();
        $this->assertGreaterThanOrEqual(1, $list['summary']['open_critical']);
        $this->assertGreaterThanOrEqual(2, collect($list['data'])->firstWhere('id', $datasetId)['open_drift']['critical']);

        $this->as('analyst@emgs.demo')->postJson("/api/v1/trust/drift/{$amount['id']}/acknowledge")->assertForbidden();
        $this->as(self::ENGINEER)->postJson("/api/v1/trust/drift/{$amount['id']}/acknowledge")->assertOk()->assertJsonPath('data.status', 'acknowledged');
        $this->as(self::ENGINEER)->postJson("/api/v1/trust/drift/{$amount['id']}/acknowledge")->assertStatus(422);
        $this->as('viewer@emgs.demo')->getJson('/api/v1/trust')->assertForbidden();
    }

    public function test_a_source_cannot_be_deleted_while_its_data_is_in_use_and_cleans_up_fully_after(): void
    {
        $up = $this->upload('order_date,region,amount', fn ($i) => sprintf('2026-03-%02d,%s,%d', ($i % 27) + 1, ['North', 'South'][$i % 2], $i * 10), 40);
        $model = $this->as(self::ENGINEER)->postJson("/api/v1/datasets/{$up['dataset']['id']}/semantic-model")->assertCreated()->json('data.key');
        $dashboard = $this->as('admin@emgs.demo')->postJson('/api/v1/dashboards', ['title' => 'Trust test', 'widgets' => [
            ['type' => 'kpi', 'title' => 'Amount', 'query' => ['model' => $model, 'metrics' => ['total_amount']]],
        ]])->assertCreated()->json('data.id');
        $source = $up['source']['id'];

        $impact = $this->as(self::ENGINEER)->getJson("/api/v1/data-sources/{$source}/impact")->assertOk()->json('data');
        $this->assertFalse($impact['can_delete']);
        // The dashboard is the administrator's private one: it blocks deletion but is not named to the engineer.
        $this->assertSame([], $impact['blocking']['dashboards']);
        $this->assertSame(1, $impact['blocking']['hidden']);
        $this->assertSame(['Trust test'], array_column($this->as('admin@emgs.demo')->getJson("/api/v1/data-sources/{$source}/impact")->json('data.blocking.dashboards'), 'title'));
        $this->assertSame([$model], array_column($impact['models'], 'key'));
        $this->assertTrue($impact['datasets'][0]['drops_table']);
        $this->as(self::ENGINEER)->deleteJson("/api/v1/data-sources/{$source}")->assertStatus(409);

        $this->as('admin@emgs.demo')->deleteJson("/api/v1/dashboards/{$dashboard}")->assertNoContent();
        $this->as(self::ENGINEER)->deleteJson("/api/v1/data-sources/{$source}")->assertNoContent();
        $table = $up['dataset']['physical_table'];
        $this->assertNull(DB::selectOne('SELECT to_regclass(?) AS t', ["analytics.{$table}"])->t, 'the table AIXBI created is dropped');
        $this->assertFalse(TenantScopeBypass::run(fn () => SemanticModel::where('key', $model)->exists()), 'its model and metrics go with it');
        $this->assertFalse(TenantScopeBypass::run(fn () => Dataset::whereKey($up['dataset']['id'])->exists()));

        // The warehouse feeding the demo is in use everywhere, so it is protected.
        $warehouse = TenantScopeBypass::run(fn () => DataSource::where('name', 'Student Processing Warehouse')->value('id'));
        $this->as('admin@emgs.demo')->deleteJson("/api/v1/data-sources/{$warehouse}")->assertStatus(409);
    }
}
