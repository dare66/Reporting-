<?php

namespace Tests\Feature;

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
 * Ingestion writes real tables that the read-only analytical connection must
 * see, so this test commits (no wrapping transaction) and cleans up after itself.
 */
class UploadIngestionTest extends TestCase
{
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
            $datasets = Dataset::where('name', 'like', 'ds_%sales_orders')->orWhere('name', 'like', 'ds_%webhook_orders')->get();
            foreach ($datasets as $d) {
                DB::statement('DROP TABLE IF EXISTS analytics."'.$d->physical_table.'"');
                SemanticModel::where('base_dataset_id', $d->id)->delete();
                $d->delete();
            }
            DataSource::whereIn('name', ['Sales Orders (upload)', 'Webhook Orders'])->delete();
        });
        parent::tearDown();
    }

    public function test_upload_profile_generate_model_and_query(): void
    {
        $csv = "order_date,region,product,amount,units\n";
        foreach (range(1, 60) as $i) {
            $csv .= sprintf("2026-%02d-%02d,%s,%s,%.2f,%d\n", ($i % 9) + 1, ($i % 27) + 1, ['North', 'South', 'East'][$i % 3], ['Alpha', 'Beta'][$i % 2], 100 + $i * 3.5, $i % 7 + 1);
        }
        $file = UploadedFile::fake()->createWithContent('Sales Orders.csv', $csv);
        $up = $this->as('engineer@emgs.demo')->post('/api/v1/data/upload', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame(60, $up['run']['records']);
        $types = collect($up['dataset']['fields'])->pluck('data_type', 'name');
        $this->assertSame(['date', 'string', 'string', 'decimal', 'integer'], [$types['order_date'], $types['region'], $types['product'], $types['amount'], $types['units']]);

        $proposal = $this->as('engineer@emgs.demo')->getJson("/api/v1/datasets/{$up['dataset']['id']}/semantic-proposal")->assertOk()->json('data');
        $this->assertSame('order_date', $proposal['time_dimension']);
        $this->assertContains('total_amount', array_column($proposal['metrics'], 'key'));

        $model = $this->as('engineer@emgs.demo')->postJson("/api/v1/datasets/{$up['dataset']['id']}/semantic-model")->assertCreated()->json('data');
        $rows = $this->as('engineer@emgs.demo')->postJson('/api/v1/query', ['model' => $model['key'], 'metrics' => ['total_amount'], 'dimensions' => ['region']])->assertOk()->json('rows');
        $this->assertCount(3, $rows);
        $this->assertEqualsWithDelta(array_sum(array_map(fn ($i) => 100 + $i * 3.5, range(1, 60))), array_sum(array_column($rows, 'total_amount')), 0.01);
    }

    public function test_webhook_accepts_a_record_or_a_list_and_rejects_bad_tokens(): void
    {
        // The web app always sends a (possibly empty) config object.
        $created = $this->as('engineer@emgs.demo')->postJson('/api/v1/data-sources', ['connector_key' => 'webhook', 'name' => 'Webhook Orders', 'config' => ['token' => 'chosen-by-client']])
            ->assertCreated()->assertJsonMissingPath('data.config');
        $url = parse_url($created->json('ingest.url'), PHP_URL_PATH);
        $this->assertStringNotContainsString('chosen-by-client', $url);
        $sourceId = $created->json('data.id');

        $this->postJson($url, ['order_id' => 1, 'amount' => 12.5])->assertStatus(202)->assertJson(['accepted' => 1]);
        $this->postJson($url, [['order_id' => 2, 'amount' => 3], ['order_id' => 3, 'amount' => 4]])->assertStatus(202)->assertJson(['accepted' => 2]);
        $this->postJson($url, [])->assertStatus(422);
        $this->postJson("/api/v1/ingest/webhook/{$sourceId}/wrong-token", ['order_id' => 4])->assertForbidden();
    }
}
