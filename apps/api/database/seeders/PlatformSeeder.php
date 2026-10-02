<?php

namespace Database\Seeders;

use App\Models\AiAgent;
use App\Models\AiModel;
use App\Models\DataConnector;
use App\Models\FeatureFlag;
use App\Models\Permission;
use App\Models\Prompt;
use App\Models\ReportTemplate;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Platform-level reference data shared by every tenant. Idempotent. */
class PlatformSeeder extends Seeder
{
    public const PERMISSIONS = [
        'dashboards.view' => ['bi', 'View dashboards'],
        'dashboards.manage' => ['bi', 'Create and edit dashboards'],
        'reports.view' => ['reporting', 'View reports'],
        'reports.manage' => ['reporting', 'Create and edit reports'],
        'reports.publish' => ['reporting', 'Publish, archive and roll back reports'],
        'reports.export' => ['reporting', 'Export reports (PDF, PPTX, Excel)'],
        'query.run' => ['bi', 'Run semantic queries'],
        'query.explain' => ['bi', 'See generated SQL and query plans'],
        'data.view' => ['data', 'View data sources and datasets'],
        'data.manage' => ['data', 'Connect sources and run ingestion'],
        'data.sensitive' => ['data', 'Access fields classified as sensitive'],
        'semantic.view' => ['data', 'View semantic models'],
        'semantic.manage' => ['data', 'Edit semantic models'],
        'ai.use' => ['ai', 'Use the AI analyst'],
        'ai.admin' => ['ai', 'Manage AI models, prompts and agents'],
        'alerts.view' => ['alerts', 'View alerts'],
        'alerts.manage' => ['alerts', 'Create and edit alert rules'],
        'analytics.advanced' => ['analytics', 'Forecasts, anomalies and scenarios'],
        'governance.view' => ['governance', 'View lineage, AI usage and data quality'],
        'audit.view' => ['governance', 'View audit logs'],
        'admin.users' => ['admin', 'Manage users and role assignments'],
        'admin.roles' => ['admin', 'Manage roles and permissions'],
        'admin.org' => ['admin', 'Manage organisation settings, branding and feature flags'],
        'admin.system' => ['admin', 'View system health'],
        'collab.comment' => ['collaboration', 'Comment, mention and annotate'],
    ];

    public const ROLES = [
        'super_admin' => ['Super Admin', 'admin', 'Platform-wide administration.', ['*']],
        'tenant_admin' => ['Tenant Admin', 'admin', 'Administers one organisation.', ['*']],
        'ceo' => ['CEO', 'executive', 'Executive intelligence across the organisation.', [
            'dashboards.view', 'reports.view', 'reports.export', 'query.run', 'ai.use', 'alerts.view', 'alerts.manage',
            'analytics.advanced', 'collab.comment', 'semantic.view', 'reports.manage', 'reports.publish',
        ]],
        'executive' => ['Executive', 'executive', 'Directors and C-suite.', [
            'dashboards.view', 'reports.view', 'reports.export', 'query.run', 'ai.use', 'alerts.view', 'alerts.manage',
            'analytics.advanced', 'collab.comment', 'semantic.view', 'reports.manage',
        ]],
        'manager' => ['Manager', 'executive', 'Operational managers; data limited by row-level security.', [
            'dashboards.view', 'reports.view', 'reports.export', 'query.run', 'ai.use', 'alerts.view', 'alerts.manage', 'collab.comment', 'semantic.view',
        ]],
        'analyst' => ['Analyst', 'analyst', 'Explores data and builds analyses.', [
            'dashboards.view', 'dashboards.manage', 'reports.view', 'reports.manage', 'reports.export', 'query.run', 'query.explain',
            'data.view', 'semantic.view', 'ai.use', 'alerts.view', 'alerts.manage', 'analytics.advanced', 'governance.view', 'collab.comment',
        ]],
        'data_engineer' => ['Data Engineer', 'engineer', 'Owns connectors, ingestion and the semantic layer.', [
            'dashboards.view', 'reports.view', 'query.run', 'query.explain', 'data.view', 'data.manage', 'data.sensitive',
            'semantic.view', 'semantic.manage', 'ai.use', 'governance.view', 'audit.view', 'admin.system', 'collab.comment',
        ]],
        'report_designer' => ['Report Designer', 'analyst', 'Designs report layouts and templates.', [
            'dashboards.view', 'dashboards.manage', 'reports.view', 'reports.manage', 'reports.publish', 'reports.export', 'query.run', 'semantic.view', 'ai.use', 'collab.comment',
        ]],
        'viewer' => ['Viewer', 'executive', 'Read-only consumer.', ['dashboards.view', 'reports.view']],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $key => [$group, $description]) {
            Permission::updateOrCreate(['key' => $key], ['group' => $group, 'description' => $description]);
        }
        Permission::updateOrCreate(['key' => '*'], ['group' => 'admin', 'description' => 'All permissions']);

        foreach (self::ROLES as $key => [$name, $experience, $description, $perms]) {
            $role = Role::updateOrCreate(['organisation_id' => null, 'key' => $key], [
                'name' => $name, 'experience' => $experience, 'description' => $description, 'is_system' => true,
            ]);
            $role->permissions()->sync(Permission::whereIn('key', $perms)->pluck('id'));
        }

        $this->connectors();
        $this->aiRegistry();
        $this->templates();

        foreach (['ai.llm_planner' => true, 'mobile.offline_cache' => true, 'viz.three_d' => true, 'reports.scheduling' => true] as $key => $on) {
            FeatureFlag::updateOrCreate(['organisation_id' => null, 'key' => $key], ['enabled' => $on]);
        }
    }

    private function connectors(): void
    {
        $sql = [['key' => 'host', 'type' => 'string', 'required' => true], ['key' => 'port', 'type' => 'integer'], ['key' => 'database', 'type' => 'string', 'required' => true],
            ['key' => 'username', 'type' => 'string', 'required' => true], ['key' => 'password', 'type' => 'secret'], ['key' => 'schema', 'type' => 'string']];
        $all = [
            ['postgresql', 'PostgreSQL', 'database', ['full_refresh', 'incremental'], $sql, 'available'],
            ['mysql', 'MySQL', 'database', ['full_refresh', 'incremental'], $sql, 'available'],
            ['mariadb', 'MariaDB', 'database', ['full_refresh', 'incremental'], $sql, 'available'],
            ['csv', 'CSV', 'file', ['full_refresh'], [['key' => 'file', 'type' => 'file', 'required' => true]], 'available'],
            ['excel', 'Excel', 'file', ['full_refresh'], [['key' => 'file', 'type' => 'file', 'required' => true]], 'available'],
            ['json', 'JSON', 'file', ['full_refresh'], [['key' => 'file', 'type' => 'file', 'required' => true]], 'available'],
            ['rest_api', 'REST API', 'api', ['full_refresh', 'polling'], [['key' => 'url', 'type' => 'string', 'required' => true], ['key' => 'records_path', 'type' => 'string'], ['key' => 'auth_header', 'type' => 'secret']], 'available'],
            ['webhook', 'Webhook', 'api', ['webhook'], [], 'available'],
            ['sqlserver', 'SQL Server', 'database', ['full_refresh', 'incremental', 'cdc'], $sql, 'planned'],
            ['oracle', 'Oracle', 'database', ['full_refresh', 'incremental', 'cdc'], $sql, 'planned'],
            ['mongodb', 'MongoDB', 'database', ['full_refresh', 'incremental'], [['key' => 'uri', 'type' => 'secret', 'required' => true]], 'planned'],
            ['clickhouse', 'ClickHouse', 'warehouse', ['full_refresh', 'incremental'], $sql, 'planned'],
            ['graphql', 'GraphQL', 'api', ['full_refresh', 'polling'], [['key' => 'url', 'type' => 'string', 'required' => true], ['key' => 'query', 'type' => 'text']], 'planned'],
            ['s3', 'Amazon S3', 'storage', ['full_refresh', 'incremental'], [['key' => 'bucket', 'type' => 'string', 'required' => true], ['key' => 'prefix', 'type' => 'string']], 'planned'],
            ['kafka', 'Kafka', 'stream', ['streaming'], [['key' => 'brokers', 'type' => 'string', 'required' => true], ['key' => 'topic', 'type' => 'string', 'required' => true]], 'planned'],
        ];
        foreach ($all as [$key, $name, $category, $caps, $schema, $status]) {
            DataConnector::updateOrCreate(['key' => $key], ['name' => $name, 'category' => $category, 'capabilities' => $caps, 'config_schema' => $schema, 'status' => $status]);
        }
    }

    private function aiRegistry(): void
    {
        $agents = [
            ['intent', 'Intent Agent', 'Classifies the request: question, investigation, report, dashboard, alert, forecast or what-if.', 'understand', []],
            ['semantic', 'Semantic Model Agent', 'Maps business language to governed metrics, dimensions and time ranges.', 'understand', ['intent']],
            ['planner', 'Query Planner', 'Builds semantic query plans; never writes free-form SQL.', 'plan', ['semantic']],
            ['sql', 'SQL Agent', 'Compiles plans through the permission-aware semantic compiler.', 'execute', ['planner']],
            ['validation', 'Data Validation Agent', 'Checks completeness, freshness and partial periods before analysis.', 'execute', ['sql']],
            ['analytics', 'Analytics Agent', 'Computes changes, contributions and comparisons deterministically.', 'analyse', ['validation']],
            ['anomaly', 'Anomaly Agent', 'Detects statistical and seasonal anomalies.', 'analyse', ['analytics']],
            ['root_cause', 'Root Cause Agent', 'Decomposes changes across dimensions to find drivers.', 'analyse', ['anomaly']],
            ['forecast', 'Forecast Agent', 'Projects metrics with confidence intervals.', 'analyse', ['analytics']],
            ['visualization', 'Visualization Agent', 'Selects the chart that answers the question.', 'present', ['root_cause', 'forecast']],
            ['narrative', 'Narrative Agent', 'Writes executive language bound to evidence.', 'present', ['visualization']],
            ['report', 'Report Agent', 'Assembles multi-section reports from analyses.', 'present', ['narrative']],
            ['data_quality', 'Data Quality Agent', 'Profiles datasets: missing values, duplicates, outliers, freshness, schema drift.', 'govern', []],
            ['governance', 'Governance Agent', 'Enforces permissions, sensitivity and auditability on every step.', 'govern', []],
        ];
        foreach ($agents as [$key, $name, $desc, $stage, $up]) {
            AiAgent::updateOrCreate(['key' => $key], ['name' => $name, 'description' => $desc, 'stage' => $stage, 'upstream' => $up]);
        }

        foreach ([
            ['anthropic', 'claude-sonnet-5-5', 'planner', 3, 15, true],
            ['anthropic', 'claude-sonnet-5-5', 'narrative', 3, 15, true],
            ['anthropic', 'claude-opus-5-5', 'narrative', 15, 75, false],
            ['anthropic', 'claude-haiku-4-5-20251001', 'planner', 1, 5, false],
            ['aixbi', 'deterministic-semantic-planner', 'planner', 0, 0, false],
        ] as [$provider, $model, $purpose, $in, $out, $default]) {
            AiModel::updateOrCreate(['provider' => $provider, 'model' => $model, 'purpose' => $purpose], [
                'cost_per_mtok_in' => $in, 'cost_per_mtok_out' => $out, 'is_default' => $default, 'enabled' => true,
            ]);
        }

        Prompt::updateOrCreate(['key' => 'planner.system', 'version' => 1], ['is_active' => true, 'template' => 'You translate business questions into semantic query plans over the provided catalog. Use only listed metric and dimension keys. Never invent data.']);
        Prompt::updateOrCreate(['key' => 'narrative.system', 'version' => 1], ['is_active' => true, 'template' => 'You write concise executive narratives. Every number you state must appear in the supplied facts, cited by fact id.']);
    }

    private function templates(): void
    {
        $exec = [
            ['type' => 'summary', 'title' => 'Executive Summary'],
            ['type' => 'kpis', 'title' => 'KPI Overview', 'metrics' => ['revenue.revenue', 'applications.total_applications', 'decisions.sla_compliance', 'decisions.approval_rate', 'applications.high_risk_applications']],
            ['type' => 'chart', 'title' => 'Application Trend', 'metric' => 'applications.total_applications', 'grain' => 'month', 'range' => 'last_12_months'],
            ['type' => 'chart', 'title' => 'Revenue Trend', 'metric' => 'revenue.revenue', 'grain' => 'month', 'range' => 'last_12_months'],
            ['type' => 'breakdown', 'title' => 'Top Markets', 'metric' => 'applications.total_applications', 'dimension' => 'country', 'limit' => 8],
            ['type' => 'anomalies', 'title' => 'Anomalies', 'metrics' => ['decisions.rejection_rate', 'decisions.sla_compliance', 'applications.total_applications']],
            ['type' => 'root_cause', 'title' => 'Root Cause', 'metric' => 'decisions.sla_compliance'],
            ['type' => 'forecast', 'title' => '6-Month Outlook', 'metric' => 'applications.total_applications', 'horizon' => 6],
            ['type' => 'risks', 'title' => 'Risks & Areas Requiring Attention'],
        ];
        $ops = [
            ['type' => 'summary', 'title' => 'Operational Summary'],
            ['type' => 'kpis', 'title' => 'Operational KPIs', 'metrics' => ['decisions.decided_applications', 'decisions.avg_processing_days', 'decisions.sla_compliance', 'applications.pending_applications', 'decisions.rejection_rate']],
            ['type' => 'chart', 'title' => 'SLA Trend', 'metric' => 'decisions.sla_compliance', 'grain' => 'week', 'range' => 'last_6_months'],
            ['type' => 'breakdown', 'title' => 'SLA by Institution', 'metric' => 'decisions.sla_compliance', 'dimension' => 'institution', 'limit' => 10, 'sort' => 'asc'],
            ['type' => 'root_cause', 'title' => 'SLA Drivers', 'metric' => 'decisions.sla_compliance'],
            ['type' => 'anomalies', 'title' => 'Operational Anomalies', 'metrics' => ['decisions.rejection_rate', 'decisions.avg_processing_days']],
            ['type' => 'forecast', 'title' => 'Demand Forecast', 'metric' => 'applications.total_applications', 'horizon' => 3],
            ['type' => 'risks', 'title' => 'Operational Risks'],
        ];
        $fin = [
            ['type' => 'summary', 'title' => 'Financial Summary'],
            ['type' => 'kpis', 'title' => 'Financial KPIs', 'metrics' => ['revenue.revenue', 'revenue.application_fee_revenue', 'revenue.visa_fee_revenue', 'revenue.avg_payment']],
            ['type' => 'chart', 'title' => 'Revenue Trend', 'metric' => 'revenue.revenue', 'grain' => 'month', 'range' => 'last_12_months'],
            ['type' => 'breakdown', 'title' => 'Revenue by Market', 'metric' => 'revenue.revenue', 'dimension' => 'country', 'limit' => 10],
            ['type' => 'root_cause', 'title' => 'Revenue Drivers', 'metric' => 'revenue.revenue'],
            ['type' => 'forecast', 'title' => 'Revenue Forecast', 'metric' => 'revenue.revenue', 'horizon' => 6],
            ['type' => 'risks', 'title' => 'Financial Risks'],
        ];
        $risk = [
            ['type' => 'summary', 'title' => 'Risk Summary'],
            ['type' => 'kpis', 'title' => 'Risk Indicators', 'metrics' => ['applications.high_risk_applications', 'applications.high_risk_share', 'decisions.rejection_rate', 'applications.document_issue_rate']],
            ['type' => 'breakdown', 'title' => 'High-risk by Country', 'metric' => 'applications.high_risk_applications', 'dimension' => 'country', 'limit' => 10],
            ['type' => 'anomalies', 'title' => 'Risk Anomalies', 'metrics' => ['decisions.rejection_rate', 'applications.document_issue_rate']],
            ['type' => 'root_cause', 'title' => 'Rejection Drivers', 'metric' => 'decisions.rejection_rate'],
            ['type' => 'risks', 'title' => 'Compliance Considerations'],
        ];
        $defs = [
            ['ceo', 'CEO Report', 'ceo', 'Monthly executive intelligence brief.', $exec, 'executive'],
            ['board', 'Board Report', 'board', 'Quarterly board pack with outlook.', $exec, 'executive'],
            ['monthly_management', 'Monthly Management Report', 'management', 'Cross-functional monthly review.', $exec, 'corporate'],
            ['coo', 'COO Operations Report', 'coo', 'Throughput, SLA and capacity.', $ops, 'operations'],
            ['operations', 'Operational Report', 'operations', 'Weekly operational performance.', $ops, 'operations'],
            ['cfo', 'CFO Financial Report', 'cfo', 'Revenue performance and outlook.', $fin, 'financial'],
            ['finance', 'Financial Report', 'finance', 'Revenue streams and markets.', $fin, 'financial'],
            ['risk', 'Risk Report', 'risk', 'Risk indicators and rejection drivers.', $risk, 'executive'],
            ['compliance', 'Compliance Report', 'compliance', 'Document compliance and refusals.', $risk, 'government'],
        ];
        foreach ($defs as [$key, $name, $aud, $desc, $sections, $theme]) {
            ReportTemplate::updateOrCreate(['organisation_id' => null, 'key' => $key], ['name' => $name, 'audience' => $aud, 'description' => $desc, 'sections' => $sections, 'theme' => $theme]);
        }
    }
}
