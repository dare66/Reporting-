<?php

namespace Tests\Feature;

use App\Domain\Data\SourceRemoval;
use App\Models\DatasetSnapshot;
use App\Models\DataSource;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Connecting a real PostgreSQL database: discover its tables, load them all in
 * the background, and go straight to Auto BI. The "external" database is a
 * schema created in the test database; it is reached over a real connection,
 * exactly as a customer's database would be. Commits for real and cleans up.
 */
class DatabaseOnboardingTest extends TestCase
{
    private const ENGINEER = 'engineer@emgs.demo';

    private const SCHEMA = 'crm_onboarding';

    protected function setUp(): void
    {
        parent::setUp();
        if (! TenantScopeBypass::run(fn () => Organisation::where('slug', 'emgs')->exists())) {
            Artisan::call('migrate:fresh', ['--seed' => true]);
        }
        $s = self::SCHEMA;
        DB::unprepared(<<<SQL
            DROP SCHEMA IF EXISTS {$s} CASCADE;
            CREATE SCHEMA {$s};
            CREATE TABLE {$s}.customers (customer_id int PRIMARY KEY, country text, segment text);
            CREATE TABLE {$s}.orders (order_id text PRIMARY KEY, customer_id int REFERENCES {$s}.customers, order_date date, status text, amount numeric(12,2));
            CREATE TABLE {$s}.empty_archive (id int);
            INSERT INTO {$s}.customers SELECT g, (ARRAY['Malaysia','Indonesia','China','India'])[1 + g % 4], (ARRAY['Retail','Corporate'])[1 + g % 2] FROM generate_series(1, 80) g;
            INSERT INTO {$s}.orders SELECT 'ORD-' || g, 1 + g % 80, current_date - (g % 300), (ARRAY['Completed','Completed','Completed','Cancelled','Pending'])[1 + g % 5], 50 + (g % 40) * 12.5 FROM generate_series(1, 600) g;
            CREATE VIEW {$s}.big_orders AS SELECT * FROM {$s}.orders WHERE amount > 400;
        SQL);
    }

    protected function tearDown(): void
    {
        TenantScopeBypass::run(function () {
            $admin = User::where('email', 'admin@emgs.demo')->first();
            app(TenantContext::class)->setOrganisation($admin->organisation_id);
            foreach (DataSource::where('name', 'CRM database')->get() as $source) {
                app(SourceRemoval::class)->remove($source, $admin);
            }
            app(TenantContext::class)->clear();
        });
        DB::unprepared('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        parent::tearDown();
    }

    private function connect(): string
    {
        $db = config('database.connections.pgsql');

        return $this->as(self::ENGINEER)->postJson('/api/v1/data-sources', ['connector_key' => 'postgresql', 'name' => 'CRM database', 'config' => [
            'host' => 'localhost', 'port' => $db['port'], 'database' => $db['database'], 'username' => $db['username'], 'password' => $db['password'], 'schema' => self::SCHEMA,
        ]])->assertCreated()->json('data.id');
    }

    public function test_a_connected_database_is_discovered_loaded_and_ready_for_auto_bi(): void
    {
        $id = $this->connect();
        $this->as(self::ENGINEER)->postJson("/api/v1/data-sources/{$id}/test")->assertOk()->assertJsonPath('data.ok', true);

        $tables = collect($this->as(self::ENGINEER)->getJson("/api/v1/data-sources/{$id}/tables")->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['big_orders', 'customers', 'empty_archive', 'orders'], $tables->keys()->all());
        $this->assertSame('view', $tables['big_orders']['type']);

        $this->as('viewer@emgs.demo')->postJson("/api/v1/data-sources/{$id}/load")->assertForbidden();
        $this->as(self::ENGINEER)->postJson("/api/v1/data-sources/{$id}/load", ['tables' => ['orders', 'nope']])->assertStatus(422);

        // Everything at once (the queue runs inline in tests; in production a worker runs it in the background).
        $this->as(self::ENGINEER)->postJson("/api/v1/data-sources/{$id}/load")->assertStatus(202);
        $load = collect(TenantScopeBypass::run(fn () => DataSource::find($id)->load_progress));
        $this->assertSame('done', $load['status']);
        $byTable = collect($load['tables'])->keyBy('table');
        $this->assertSame(600, $byTable['orders']['rows']);
        $this->assertSame(80, $byTable['customers']['rows']);
        $this->assertSame('loaded', $byTable['big_orders']['status'], 'views load like tables');
        $this->assertSame('failed', $byTable['empty_archive']['status'], 'one empty table does not stop the others');
        $this->assertStringContainsString('no records', $byTable['empty_archive']['error']);

        $types = collect($this->as(self::ENGINEER)->getJson("/api/v1/datasets/{$byTable['orders']['dataset_id']}")->json('data.fields'))->pluck('data_type', 'name');
        $this->assertSame(['date', 'decimal', 'string'], [$types['order_date'], $types['amount'], $types['status']]);

        // Auto BI designs straight from the loaded database.
        $plan = $this->as(self::ENGINEER)->getJson("/api/v1/data-sources/{$id}/auto-bi")->assertOk()->json('data');
        $this->assertSame('Orders', $plan['fact']['label']);
        $joins = collect($plan['relationships'])->where('from_dataset', $plan['fact']['name'])->pluck('to')->all();
        $this->assertTrue(collect($joins)->contains(fn ($to) => str_ends_with($to, 'customers.customer_id')), 'orders join to customers');
        $this->assertContains('completed_rate', array_column($plan['kpis'], 'key'));
        $this->assertContains('total_amount', array_column($plan['kpis'], 'key'));

        // The requester is told it is ready, with a link straight to Auto BI.
        $this->assertDatabaseHas('notifications', ['user_id' => $this->user(self::ENGINEER)->id, 'link' => "/data/sources/{$id}/auto-bi"]);

        // A reload updates the same datasets, so drift is measured between loads; the row limit is respected.
        $this->as(self::ENGINEER)->postJson("/api/v1/data-sources/{$id}/load", ['tables' => ['orders'], 'row_limit' => 100])->assertStatus(202);
        $reloaded = TenantScopeBypass::run(fn () => DataSource::find($id)->load_progress['tables'][0]);
        $this->assertSame($byTable['orders']['dataset_id'], $reloaded['dataset_id']);
        $this->assertSame(100, $reloaded['rows']);
        // A limited load keeps the most recent rows, not whichever rows the database returns first.
        $newest = TenantScopeBypass::run(fn () => \App\Models\Dataset::find($reloaded['dataset_id'])->freshness_at->toDateString());
        $this->assertSame(now()->toDateString(), $newest);
        $this->assertSame(2, TenantScopeBypass::run(fn () => DatasetSnapshot::where('dataset_id', $reloaded['dataset_id'])->count()));

        // Incremental: only rows after the newest one loaded are fetched and appended.
        $incremental = function () use ($id) {
            $this->as(self::ENGINEER)->postJson("/api/v1/data-sources/{$id}/load", ['tables' => ['orders'], 'mode' => 'incremental'])->assertStatus(202);

            return TenantScopeBypass::run(fn () => DataSource::find($id)->load_progress['tables'][0]);
        };
        $table = TenantScopeBypass::run(fn () => \App\Models\Dataset::find($reloaded['dataset_id'])->physical_table);
        $nothing = $incremental();
        $this->assertSame(['incremental', 0, $reloaded['dataset_id']], [$nothing['mode'], $nothing['rows'], $nothing['dataset_id']]);
        DB::unprepared('INSERT INTO '.self::SCHEMA.".orders SELECT 'NEW-' || g, 1, current_date + 1, 'Completed', 99 FROM generate_series(1, 7) g");
        $more = $incremental();
        $this->assertSame(['incremental', 7], [$more['mode'], $more['rows']]);
        $this->assertSame(107, DB::connection('analytics')->table("analytics.{$table}")->count());
        $this->assertSame(7, DB::connection('analytics')->table("analytics.{$table}")->where('order_id', 'like', 'NEW-%')->count());
        $this->assertSame(0, $incremental()['rows'], 'the watermark moved past the new rows');
    }
}
