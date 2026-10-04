<?php

namespace Database\Seeders;

use App\Domain\Data\DatasetRegistrar;
use App\Domain\Metrics\MetricStore;
use App\Domain\Semantic\SemanticModelImporter;
use App\Models\AlertRule;
use App\Models\Dashboard;
use App\Models\DataSource;
use App\Models\Department;
use App\Models\IngestionRun;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\SemanticModel;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demonstration tenant: a fictional international-student processing agency.
 * All people and institutions are invented.
 */
class DemoTenantSeeder extends Seeder
{
    public const PASSWORD = 'Demo@2026!';

    public function run(DatasetRegistrar $registrar, SemanticModelImporter $importer, MetricStore $store): void
    {
        $this->loadAnalyticsData();

        $org = Organisation::updateOrCreate(['slug' => 'emgs'], [
            'name' => 'Education Malaysia Global Services (EMGS)', 'industry' => 'education', 'currency' => 'MYR', 'timezone' => 'Asia/Kuala_Lumpur',
            'branding' => ['accent' => '#E8B04B', 'logo_text' => 'EMGS'],
        ]);
        app(TenantContext::class)->setOrganisation($org->id);

        $depts = [];
        foreach (['Executive Office', 'Operations', 'Finance', 'Risk & Compliance', 'Data & Analytics', 'Information Technology'] as $name) {
            $depts[$name] = Department::updateOrCreate(['organisation_id' => $org->id, 'name' => $name]);
        }
        $teams = [];
        foreach ([['Processing Centre', 'Operations'], ['Asia Markets', 'Operations'], ['Revenue Assurance', 'Finance'], ['BI Engineering', 'Data & Analytics']] as [$name, $dept]) {
            $teams[$name] = Team::updateOrCreate(['organisation_id' => $org->id, 'name' => $name], ['department_id' => $depts[$dept]->id]);
        }

        $people = [
            ['ceo@emgs.demo', 'Mohammed Hakim', 'Chief Executive Officer', 'ceo', 'Executive Office', null, []],
            ['coo@emgs.demo', 'Aisha Tan', 'Chief Operating Officer', 'executive', 'Operations', null, []],
            ['cfo@emgs.demo', 'Rajesh Kumar', 'Chief Financial Officer', 'executive', 'Finance', 'Revenue Assurance', []],
            ['manager.asia@emgs.demo', 'Wei Ling Chen', 'Head of Asia Markets', 'manager', 'Operations', 'Asia Markets', ['country_codes' => ['CN', 'VN', 'TH', 'JP', 'KR', 'ID']]],
            ['analyst@emgs.demo', 'Priya Nair', 'Senior Business Analyst', 'analyst', 'Data & Analytics', 'BI Engineering', []],
            ['engineer@emgs.demo', 'Daniel Lim', 'Data Engineer', 'data_engineer', 'Data & Analytics', 'BI Engineering', []],
            ['designer@emgs.demo', 'Sofia Rahman', 'Report Designer', 'report_designer', 'Data & Analytics', null, []],
            ['admin@emgs.demo', 'Sarah Wong', 'Platform Administrator', 'tenant_admin', 'Information Technology', null, []],
            ['viewer@emgs.demo', 'Hafiz Ismail', 'Board Observer', 'viewer', 'Executive Office', null, []],
        ];
        $users = [];
        foreach ($people as [$email, $name, $title, $role, $dept, $team, $attrs]) {
            $user = User::updateOrCreate(['email' => $email], [
                'organisation_id' => $org->id, 'name' => $name, 'title' => $title, 'password' => self::PASSWORD,
                'department_id' => $depts[$dept]->id, 'team_id' => $team ? $teams[$team]->id : null, 'attributes' => $attrs, 'status' => 'active',
            ]);
            $user->roles()->sync([Role::whereNull('organisation_id')->where('key', $role)->value('id')]);
            $users[$role] ??= $user;
        }

        // Data platform: source → datasets (introspected + profiled) → semantic models.
        $source = DataSource::updateOrCreate(['organisation_id' => $org->id, 'name' => 'Student Processing Warehouse'], [
            'connector_key' => 'postgresql', 'status' => 'connected', 'sync_mode' => 'incremental', 'schedule' => '*/30 * * * *',
            'config' => ['host' => 'internal', 'database' => 'aixbi', 'schema' => 'analytics', 'managed' => true],
            'last_sync_at' => now(), 'created_by' => $users['data_engineer']->id,
        ]);
        $started = microtime(true);
        $tables = [
            ['applications', 'Applications', ['applicant_ref'], 'One row per student application, including outcome, processing time, SLA and risk.'],
            ['countries', 'Countries', [], 'Source markets with region and coordinates.'],
            ['institutions', 'Institutions', [], 'Higher-education institutions (IHEs).'],
            ['courses', 'Courses', [], 'Programmes with field, level and fee.'],
            ['payments', 'Payments', [], 'Fee payments: application, visa and medical.'],
            ['processing_capacity', 'Processing Capacity', [], 'Daily processing officers and capacity.'],
            ['attendance_monthly', 'Attendance', [], 'Monthly enrolment and attendance by institution.'],
        ];
        $records = 0;
        foreach ($tables as [$table, $label, $sensitive, $desc]) {
            $records += $registrar->register($source, 'analytics', $table, $label, $sensitive, $desc)->row_count;
        }
        IngestionRun::create([
            'organisation_id' => $org->id, 'data_source_id' => $source->id, 'mode' => 'full', 'status' => 'succeeded',
            'records' => $records, 'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'log' => [['level' => 'info', 'message' => 'Initial load and profiling of 7 tables.']],
            'started_at' => now()->subSeconds(max(1, (int) (microtime(true) - $started))), 'finished_at' => now(),
        ]);

        foreach (['applications', 'decisions', 'revenue', 'capacity'] as $key) {
            $importer->import($org->id, json_decode(file_get_contents(__DIR__."/semantic/{$key}.json"), true));
        }
        $this->governMetrics($store, $users);

        $this->dashboards($org, $users['ceo'], $users['analyst']);

        AlertRule::updateOrCreate(['organisation_id' => $org->id, 'name' => 'Processing SLA below 90%'], [
            'owner_id' => $users['executive']->id, 'semantic_model_id' => SemanticModel::where('key', 'decisions')->value('id'),
            'metric_key' => 'sla_compliance', 'operator' => 'lt', 'threshold' => 0.9, 'window' => 'last_7_days',
            'frequency_minutes' => 15, 'channels' => ['in_app', 'email', 'push'], 'recipients' => [$users['ceo']->id, $users['executive']->id],
            'created_via' => 'conversation',
        ]);
        AlertRule::updateOrCreate(['organisation_id' => $org->id, 'name' => 'Rejection rate above 9%'], [
            'owner_id' => $users['analyst']->id, 'semantic_model_id' => SemanticModel::where('key', 'decisions')->value('id'),
            'metric_key' => 'rejection_rate', 'operator' => 'gt', 'threshold' => 0.09, 'window' => 'last_7_days',
            'frequency_minutes' => 60, 'channels' => ['in_app'], 'recipients' => [$users['ceo']->id, $users['analyst']->id],
        ]);
    }

    private function loadAnalyticsData(): void
    {
        $sql = strtr(file_get_contents(__DIR__.'/sql/analytics_demo.sql'), [
            '{{END_DATE}}' => now()->toDateString(),
            '{{SCALE}}' => (string) config('aixbi.demo.scale'),
            '{{READER_PASSWORD}}' => str_replace("'", "''", (string) config('database.connections.analytics.password')),
        ]);
        DB::unprepared($sql);
        DB::purge('analytics');
    }

    private function dashboards(Organisation $org, User $ceo, User $analyst): void
    {
        $exec = Dashboard::updateOrCreate(['organisation_id' => $org->id, 'title' => 'Executive Overview'], [
            'owner_id' => $ceo->id, 'description' => 'Business health across demand, operations, revenue and risk.',
            'is_home' => true, 'visibility' => 'organisation', 'theme' => 'dark-intelligence',
            'sections' => [['key' => 'pulse', 'label' => 'Pulse'], ['key' => 'demand', 'label' => 'Demand'], ['key' => 'operations', 'label' => 'Operations'], ['key' => 'risk', 'label' => 'Risk']],
        ]);
        $exec->widgets()->delete();
        $w = [
            ['kpi', 'Revenue', 'pulse', ['model' => 'revenue', 'metrics' => ['revenue'], 'time' => ['range' => 'last_30_days']], ['compare' => 'previous_period'], [0, 0, 3, 2], 10],
            ['kpi', 'Applications', 'pulse', ['model' => 'applications', 'metrics' => ['total_applications'], 'time' => ['range' => 'last_30_days']], ['compare' => 'previous_period'], [3, 0, 3, 2], 20],
            ['kpi', 'Processing SLA', 'pulse', ['model' => 'decisions', 'metrics' => ['sla_compliance'], 'time' => ['range' => 'last_30_days']], ['compare' => 'previous_period'], [6, 0, 3, 2], 30],
            ['kpi', 'High-risk', 'pulse', ['model' => 'applications', 'metrics' => ['high_risk_applications'], 'time' => ['range' => 'last_30_days']], ['compare' => 'previous_period'], [9, 0, 3, 2], 40],
            ['chart', 'Application trend', 'demand', ['model' => 'applications', 'metrics' => ['total_applications'], 'time' => ['grain' => 'month', 'range' => 'last_24_months']], ['type' => 'area'], [0, 2, 8, 4], 50],
            ['insight', 'AI insights', 'pulse', [], ['source' => 'insights'], [8, 2, 4, 4], 15],
            ['globe', 'Source markets', 'demand', ['model' => 'applications', 'metrics' => ['total_applications'], 'dimensions' => ['country', 'country_code'], 'time' => ['range' => 'last_12_months']], ['type' => 'globe', 'fallback' => 'bar'], [0, 6, 6, 5], 60],
            ['chart', 'SLA by week', 'operations', ['model' => 'decisions', 'metrics' => ['sla_compliance'], 'time' => ['grain' => 'week', 'range' => 'last_6_months']], ['type' => 'line', 'target' => 0.9], [6, 6, 6, 5], 70],
            ['chart', 'Institutions — SLA', 'operations', ['model' => 'decisions', 'metrics' => ['sla_compliance', 'decided_applications'], 'dimensions' => ['institution'], 'time' => ['range' => 'last_30_days'], 'sort' => [['key' => 'sla_compliance', 'dir' => 'asc']], 'limit' => 10], ['type' => 'bar', 'orientation' => 'horizontal'], [0, 11, 6, 5], 80],
            ['chart', 'Rejection rate', 'risk', ['model' => 'decisions', 'metrics' => ['rejection_rate'], 'time' => ['grain' => 'day', 'range' => 'last_90_days']], ['type' => 'line', 'anomalies' => true], [6, 11, 6, 5], 90],
            ['chart', 'Application funnel', 'operations', ['model' => 'applications', 'metrics' => ['total_applications'], 'dimensions' => ['stage'], 'time' => ['range' => 'last_30_days']], ['type' => 'funnel'], [0, 16, 4, 5], 100],
            ['chart', 'Revenue by stream', 'pulse', ['model' => 'revenue', 'metrics' => ['revenue'], 'dimensions' => ['payment_type'], 'time' => ['range' => 'last_12_months']], ['type' => 'donut'], [4, 16, 4, 5], 110],
            ['chart', 'Risk mix by region', 'risk', ['model' => 'applications', 'metrics' => ['high_risk_share'], 'dimensions' => ['region'], 'time' => ['range' => 'last_90_days'], 'sort' => [['key' => 'high_risk_share', 'dir' => 'desc']]], ['type' => 'bar'], [8, 16, 4, 5], 120],
        ];
        foreach ($w as [$type, $title, $section, $query, $viz, [$x, $y, $width, $h], $priority]) {
            $exec->widgets()->create(['type' => $type, 'title' => $title, 'section' => $section, 'query' => $query, 'viz' => $viz,
                'position' => ['x' => $x, 'y' => $y, 'w' => $width, 'h' => $h], 'priority' => $priority]);
        }

        $ops = Dashboard::updateOrCreate(['organisation_id' => $org->id, 'title' => 'Processing Operations'], [
            'owner_id' => $analyst->id, 'description' => 'Throughput, SLA and capacity for the processing centre.',
            'visibility' => 'organisation', 'sections' => [['key' => 'main', 'label' => 'Operations']],
        ]);
        $ops->widgets()->delete();
        foreach ([
            ['kpi', 'Decisions', ['model' => 'decisions', 'metrics' => ['decided_applications'], 'time' => ['range' => 'last_30_days']], ['compare' => 'previous_period'], [0, 0, 3, 2]],
            ['kpi', 'Avg processing time', ['model' => 'decisions', 'metrics' => ['avg_processing_days'], 'time' => ['range' => 'last_30_days']], ['compare' => 'previous_period'], [3, 0, 3, 2]],
            ['kpi', 'Backlog', ['model' => 'applications', 'metrics' => ['pending_applications'], 'time' => ['range' => 'last_30_days']], ['compare' => 'previous_period'], [6, 0, 3, 2]],
            ['kpi', 'Capacity', ['model' => 'capacity', 'metrics' => ['processing_capacity'], 'time' => ['range' => 'last_30_days']], ['compare' => 'previous_period'], [9, 0, 3, 2]],
            ['chart', 'Processing time by week', ['model' => 'decisions', 'metrics' => ['avg_processing_days'], 'time' => ['grain' => 'week', 'range' => 'last_6_months']], ['type' => 'line'], [0, 2, 6, 4]],
            ['chart', 'Decisions vs capacity', ['model' => 'decisions', 'metrics' => ['decided_applications'], 'time' => ['grain' => 'week', 'range' => 'last_6_months']], ['type' => 'bar'], [6, 2, 6, 4]],
            ['chart', 'SLA heatmap — institution × month', ['model' => 'decisions', 'metrics' => ['sla_compliance'], 'dimensions' => ['institution'], 'time' => ['grain' => 'month', 'range' => 'last_6_months']], ['type' => 'heatmap'], [0, 6, 12, 6]],
            ['table', 'Institution scorecard', ['model' => 'decisions', 'metrics' => ['decided_applications', 'approval_rate', 'avg_processing_days', 'sla_compliance'], 'dimensions' => ['institution'], 'time' => ['range' => 'last_30_days'], 'sort' => [['key' => 'decided_applications', 'dir' => 'desc']]], ['type' => 'table'], [0, 12, 12, 6]],
        ] as $i => [$type, $title, $query, $viz, [$x, $y, $width, $h]]) {
            $ops->widgets()->create(['type' => $type, 'title' => $title, 'section' => 'main', 'query' => $query, 'viz' => $viz,
                'position' => ['x' => $x, 'y' => $y, 'w' => $width, 'h' => $h], 'priority' => ($i + 1) * 10]);
        }
    }

    /**
     * The curated demo metrics are governed: owners assigned, every metric
     * approved by the data engineer, and the KPIs certified by a second person.
     *
     * @param  array<string, User>  $users  first user of each role
     */
    private function governMetrics(MetricStore $store, array $users): void
    {
        $cfo = User::where('email', 'cfo@emgs.demo')->firstOrFail();
        $coo = User::where('email', 'coo@emgs.demo')->firstOrFail();
        $engineer = $users['data_engineer'];
        foreach (SemanticModel::with('metrics')->get() as $model) {
            foreach ($model->metrics as $metric) {
                $metric->forceFill(['business_owner_id' => $model->key === 'revenue' ? $cfo->id : $coo->id, 'data_owner_id' => $engineer->id, 'version' => $metric->version + 1])->save();
                $store->recordVersion($metric, $engineer, 'Owners assigned.');
                $metric->forceFill([
                    'status' => $metric->is_kpi ? 'certified' : 'approved', 'approved_by' => $engineer->id, 'approved_at' => now()->subDays(30),
                    'certified_by' => $metric->is_kpi ? $users['tenant_admin']->id : null, 'certified_at' => $metric->is_kpi ? now()->subDays(29) : null,
                ])->save();
            }
        }
    }
}
