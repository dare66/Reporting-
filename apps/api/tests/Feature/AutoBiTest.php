<?php

namespace Tests\Feature;

use App\Domain\Data\OutboundGuard;
use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\Organisation;
use App\Models\Report;
use App\Models\SemanticModel;
use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Auto BI end to end on a realistic workbook: three related sheets in, a
 * governed model, dashboard and report out, every widget answering from the
 * uploaded data. Commits for real (ingestion creates tables) and cleans up.
 */
class AutoBiTest extends TestCase
{
    private const ADMIN = 'admin@emgs.demo';

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
            $sources = DataSource::where('name', 'Student Applications (upload)')->get();
            SemanticModel::where('key', 'auto_student_applications')->get()->each->delete();
            foreach (Dataset::whereIn('data_source_id', $sources->pluck('id'))->get() as $d) {
                DB::statement('DROP TABLE IF EXISTS analytics."'.$d->physical_table.'"');
                $d->delete();
            }
            Dashboard::where('title', 'like', 'Student Applications —%')->get()->each->delete();
            Report::where('title', 'like', 'Student Applications —%')->get()->each->delete();
            $sources->each->delete();
        });
        parent::tearDown();
    }

    private function workbook(): UploadedFile
    {
        mt_srand(7);
        $book = new Spreadsheet;
        $states = ['Selangor', 'Kuala Lumpur', 'Penang', 'Johor'];
        $inst = $book->getActiveSheet()->setTitle('Institutions');
        $inst->fromArray(['institution_id', 'institution_name', 'state'], null, 'A1');
        foreach (range(1, 6) as $i) {
            $inst->fromArray(["INS-{$i}", "University {$i}", $states[$i % 4]], null, 'A'.($i + 1));
        }
        $students = $book->createSheet()->setTitle('Students');
        $students->fromArray(['student_id', 'nationality', 'gender'], null, 'A1');
        $nations = ['Indonesia', 'China', 'Bangladesh', 'Nigeria', 'Yemen'];
        foreach (range(1, 120) as $i) {
            $students->fromArray([1000 + $i, $nations[$i % 5], $i % 2 ? 'Female' : 'Male'], null, 'A'.($i + 1));
        }
        $apps = $book->createSheet()->setTitle('Applications');
        $apps->fromArray(['application_id', 'student_id', 'institution_id', 'submitted_date', 'status', 'fee_amount', 'processing_days'], null, 'A1');
        $statuses = ['Approved', 'Approved', 'Approved', 'Rejected', 'Pending'];
        foreach (range(1, 400) as $i) {
            $row = $i + 1;
            $apps->fromArray(["APP-{$i}", 1000 + ($i % 120) + 1, 'INS-'.(($i % 6) + 1)], null, "A{$row}");
            // A real Excel date cell (serial number + date format), as people's workbooks hold them.
            $apps->setCellValue("D{$row}", ExcelDate::PHPToExcel(now()->subDays(420 - $i)->startOfDay()->getTimestamp()));
            $apps->getStyle("D{$row}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            $apps->fromArray([$statuses[$i % 5], 500 + ($i % 9) * 125, 5 + ($i % 23)], null, "E{$row}");
        }
        $path = tempnam(sys_get_temp_dir(), 'aixbi').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'Student Applications.xlsx', null, null, true);
    }

    public function test_a_workbook_becomes_a_governed_model_dashboard_and_report(): void
    {
        // The forecasting engine is tested in the AI service; here only its reply is stood in for.
        Http::fake(['ai.test/*' => Http::response(['method' => 'holt_linear', 'points' => [['period' => now()->addMonth()->startOfMonth()->toDateString(), 'value' => 30, 'lower' => 20, 'upper' => 40]], 'diagnostics' => []])]);
        $upload = $this->as(self::ADMIN)->post('/api/v1/data/upload', ['file' => $this->workbook()], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertCount(3, $upload['datasets'], 'every sheet becomes a dataset');
        $apps = collect($upload['datasets'])->firstWhere('label', 'Applications');
        $this->assertSame('date', collect($apps['fields'])->firstWhere('name', 'submitted_date')['data_type'], 'Excel dates arrive as dates');

        $plan = $this->as(self::ADMIN)->getJson("/api/v1/data-sources/{$upload['source']['id']}/auto-bi")->assertOk()->json('data');

        $this->assertSame($apps['name'], $plan['fact']['name'], 'the table that refers to the others is the fact');
        $this->assertSame('student applications', $plan['domain']['name']);
        $this->assertSame('Application', $plan['entity']);
        $joins = collect($plan['relationships'])->where('from_dataset', $apps['name'])->pluck('to')->map(fn ($t) => explode('.', $t)[1])->sort()->values()->all();
        $this->assertSame(['institution_id', 'student_id'], $joins);
        $this->assertSame(1.0, (float) collect($plan['relationships'])->first(fn ($r) => str_ends_with($r['to'], 'student_id'))['coverage']);

        $roles = collect(collect($plan['datasets'])->firstWhere('is_fact', true)['fields'])->pluck('role', 'field');
        $this->assertSame(['identifier', 'identifier', 'time', 'status', 'measure', 'measure'],
            [$roles['application_id'], $roles['student_id'], $roles['submitted_date'], $roles['status'], $roles['fee_amount'], $roles['processing_days']]);

        $kpis = collect($plan['kpis'])->keyBy('key');
        $this->assertSame('COUNT(Applications)', $kpis['total_applications']['formula']);
        $this->assertSame("COUNT(Applications WHERE Status = 'Approved') ÷ COUNT(Applications)", $kpis['approved_rate']['formula']);
        $this->assertFalse($kpis['rejected_rate']['higher_is_better']);
        $this->assertSame('currency', $kpis['total_fee_amount']['format']);
        $this->assertFalse($kpis['avg_processing_days']['higher_is_better']);
        $this->assertGreaterThan(80, collect($plan['datasets'])->firstWhere('is_fact', true)['trust']['score']);

        $purposes = array_column($plan['dashboard']['widgets'], 'purpose');
        $this->assertSame('What happened', $purposes[0]);
        $this->assertContains('What needs attention', $purposes);
        $this->assertNotEmpty(array_filter($plan['dashboard']['widgets'], fn ($w) => $w['rationale'] !== ''));

        // Viewers can look but not publish.
        $this->as('viewer@emgs.demo')->postJson("/api/v1/data-sources/{$upload['source']['id']}/auto-bi", ['kpis' => ['total_applications'], 'audience' => 'executive'])->assertForbidden();
        $this->as(self::ADMIN)->postJson("/api/v1/data-sources/{$upload['source']['id']}/auto-bi", ['kpis' => ['no_such_kpi'], 'audience' => 'executive'])->assertStatus(422);

        $published = $this->as(self::ADMIN)->postJson("/api/v1/data-sources/{$upload['source']['id']}/auto-bi", [
            'kpis' => ['total_applications', 'approved_rate', 'total_fee_amount', 'avg_processing_days'], 'audience' => 'operations',
            'labels' => ['approved_rate' => 'Approval rate'],
        ])->assertCreated()->json('data');

        // Every widget answers from the uploaded data, including dimensions joined from other sheets.
        $dashboard = $this->as(self::ADMIN)->getJson("/api/v1/dashboards/{$published['dashboard_id']}")->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(8, count($dashboard['widgets']));
        $this->assertContains('heatmap', array_map(fn ($w) => $w['viz']['type'] ?? null, $dashboard['widgets']), 'operations audience adds a heatmap');
        foreach ($dashboard['widgets'] as $w) {
            $res = $this->as(self::ADMIN)->postJson("/api/v1/dashboards/{$dashboard['id']}/widgets/{$w['id']}/data")->assertOk()->json();
            $rows = $res['kind'] === 'kpi' ? $res['data'] : $res['data']['rows'];
            $this->assertNotEmpty($rows, "{$w['title']} has data");
        }
        $card = $this->as(self::ADMIN)->postJson("/api/v1/dashboards/{$dashboard['id']}/widgets/{$dashboard['widgets'][1]['id']}/data")->json('data.0');
        $this->assertSame('Approval rate', $card['label']);
        $this->assertEqualsWithDelta(0.6, $card['value'], 0.1);

        $byState = $this->as(self::ADMIN)->postJson('/api/v1/query', ['model' => $published['model'], 'metrics' => ['total_applications'], 'dimensions' => ['state']])->assertOk()->json('rows');
        $this->assertSame(400, array_sum(array_column($byState, 'total_applications')), 'the institutions sheet joins without losing rows');

        $report = $this->as(self::ADMIN)->getJson("/api/v1/reports/{$published['report_id']}")->assertOk()->json('data');
        $types = array_column($report['sections'], 'type');
        $this->assertSame('summary', $types[0]);
        $this->assertContains('root_cause', $types);
        foreach ($report['sections'] as $s) {
            $this->assertArrayNotHasKey('error', $s['content'], "{$s['title']} built without error: ".json_encode($s['content']['error'] ?? null));
        }
    }

    public function test_connectors_refuse_loopback_and_metadata_addresses(): void
    {
        $guard = new OutboundGuard(fn (string $host) => ['internal.example' => ['10.0.0.5'], 'meta.example' => ['169.254.169.254']][$host] ?? []);
        foreach (['127.0.0.1', '::1', '169.254.169.254', '::ffff:127.0.0.1', 'meta.example'] as $host) {
            try {
                $guard->assertHostAllowed($host);
                $this->fail("{$host} should be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('not allowed', $e->getMessage());
            }
        }
        $guard->assertHostAllowed('internal.example'); // on-premise databases live on private networks
        $this->expectException(InvalidArgumentException::class);
        $guard->assertUrlAllowed('file:///etc/passwd');
    }

    public function test_a_rest_source_pointed_at_cloud_metadata_fails_its_connection_test(): void
    {
        $source = $this->as(self::ADMIN)->postJson('/api/v1/data-sources', ['connector_key' => 'rest_api', 'name' => 'Metadata probe', 'config' => ['url' => 'http://169.254.169.254/latest/meta-data/']])
            ->assertCreated()->json('data');
        $this->as(self::ADMIN)->postJson("/api/v1/data-sources/{$source['id']}/test")->assertOk()
            ->assertJsonPath('data.ok', false)->assertJsonPath('data.message', fn ($m) => str_contains($m, 'not allowed'));
        TenantScopeBypass::run(fn () => DataSource::where('id', $source['id'])->delete());
    }
}
