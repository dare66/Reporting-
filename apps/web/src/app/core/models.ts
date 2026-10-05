/**
 * Shapes of the AIXBI API payloads used by the web app.
 *
 * Derived from live responses; the API (apps/api) is the source of truth.
 * Section/widget content that varies by type is modelled as a discriminated
 * union where the UI branches on it, and as a typed record elsewhere.
 */

/** A JSON object whose keys are owned by the API (query rows, free-form configs). */
export type JsonObject = Record<string, unknown>;

export type Format = 'number' | 'percent' | 'currency' | 'duration_days' | string;
export type Sentiment = 'positive' | 'negative' | 'neutral';
export type Direction = 'up' | 'down' | 'flat';
export type Severity = 'info' | 'low' | 'medium' | 'high' | 'warning' | 'critical' | string;
export type Grain = 'day' | 'week' | 'month' | 'quarter' | 'year';

/** `{ data }` envelope used by most endpoints. */
export interface Envelope<T> {
  data: T;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

// ── Time & evidence ─────────────────────────────────────────────────────────

export interface Period {
  from: string;
  to: string;
  label: string;
}

/** A semantic query: the contract between UI, AI agents and the query engine. */
export interface SemanticQuery {
  model: string;
  metrics?: string[];
  measures?: string[];
  dimensions?: string[];
  filters?: QueryFilter[];
  /** Measure filters, compiled to HAVING. */
  having?: HavingFilter[];
  /** Quick functions, compiled to SQL window functions. */
  calculations?: Calculation[];
  time?: { range?: string | Period | { from: string; to: string }; grain?: Grain };
  sort?: { key: string; dir: 'asc' | 'desc' }[];
  limit?: number;
}

/** Dimension filter operators (see docs/design/widget-studio.md §1.1). */
export type FilterOp =
  | 'eq'
  | 'neq'
  | 'in'
  | 'not_in'
  | 'gt'
  | 'gte'
  | 'lt'
  | 'lte'
  | 'between'
  | 'not_between'
  | 'contains'
  | 'not_contains'
  | 'starts_with'
  | 'ends_with'
  | 'is_null'
  | 'not_null'
  | 'top'
  | 'bottom';

export interface QueryFilter {
  dimension: string;
  op: FilterOp;
  value?: unknown;
}

/** Value of a `top` / `bottom` ranking filter. */
export interface RankingValue {
  n: number;
  metric: string;
}

/** A dashboard-level filter: a query filter with its display label and paused state. */
export interface DashboardFilter extends QueryFilter {
  label?: string;
  disabled?: boolean;
  /** Set by clicking a chart: the widget it came from. That widget highlights instead of filtering itself. Never saved. */
  from?: string;
}

/** A chart member a viewer clicked, to filter the rest of the dashboard by. */
export interface CrossFilterPick {
  dimension: string;
  label: string;
  member: string;
}

export type HavingOp = 'eq' | 'neq' | 'gt' | 'gte' | 'lt' | 'lte' | 'between' | 'not_between';

export interface HavingFilter {
  metric: string;
  op: HavingOp;
  value: number | [number, number];
}

export type QuickFunction =
  'percent_of_total' | 'running_sum' | 'year_to_date' | 'difference' | 'percent_change' | 'moving_average' | 'rank';

export interface Calculation {
  fn: QuickFunction;
  metric: string;
  /** Moving average window, 2–24 periods. */
  window?: number;
}

/** Proof attached to every computed number: the query behind it. */
export interface Evidence {
  label?: string;
  query_hash: string;
  semantic_query?: SemanticQuery;
  query?: SemanticQuery;
  sql: string;
  executed_at: string;
  row_count: number;
  cached?: boolean;
}

// ── Query engine ────────────────────────────────────────────────────────────

export interface QueryColumn {
  key: string;
  label: string;
  role: 'dimension' | 'metric' | 'measure' | 'time' | string;
  type: string;
  format?: Format;
  /** Time columns: the bucket size. */
  grain?: Grain;
  /** Quick-function columns: the function and the metric it applies to. */
  calc?: { fn: QuickFunction; metric: string };
}

export type QueryRow = Record<string, string | number | boolean | null>;

export interface QueryResult {
  columns: QueryColumn[];
  rows: QueryRow[];
  meta: {
    query_hash: string;
    sql: string;
    duration_ms: number;
    cached: boolean;
    truncated: boolean;
    row_count: number;
    executed_at: string;
    query: SemanticQuery;
    partial_from: string | null;
  };
}

// ── Semantic layer ──────────────────────────────────────────────────────────

export interface CatalogDimension {
  key: string;
  label: string;
  type: string;
  synonyms: string[];
  description: string | null;
  is_sensitive: boolean;
  accessible: boolean;
  dataset: string;
  field: string;
}

export interface CatalogMeasure {
  key: string;
  label: string;
  aggregation: string;
  field: string | null;
  filters: QueryFilter[] | JsonObject[];
}

export interface CatalogMetric {
  key: string;
  ref: string;
  label: string;
  description: string | null;
  format: Format;
  higher_is_better: boolean;
  target: number | null;
  synonyms: string[];
  is_kpi: boolean;
  owner: string | null;
  expression: string;
  /** Built only from sum/count measures, so shares and running totals are meaningful. */
  additive: boolean;
  status: MetricStatus;
}

// ── Metric store ──

export type MetricStatus = 'proposed' | 'approved' | 'certified' | 'deprecated';
export type MetricAction = 'approve' | 'certify' | 'revoke' | 'deprecate' | 'reinstate';

export interface PersonRef {
  id: string;
  name: string;
}

export interface StoreMetric {
  ref: string;
  key: string;
  label: string;
  description: string | null;
  format: Format;
  is_kpi: boolean;
  model: { key: string; name: string };
  status: MetricStatus;
  status_note: string | null;
  replaced_by: string | null;
  version: number;
  owner: string | null;
  business_owner: PersonRef | null;
  data_owner: PersonRef | null;
  approved_by: string | null;
  approved_at: string | null;
  certified_by: string | null;
  certified_at: string | null;
  updated_at: string;
  usage: number;
}

export interface MetricMeasure {
  key: string;
  aggregation: string;
  field: string | null;
  filters: { field: string; op: string; value?: unknown }[];
}

export interface MetricSnapshot {
  label: string;
  description: string | null;
  expression: string;
  format: Format;
  higher_is_better: boolean;
  target: number | null;
  synonyms: string[];
  is_kpi: boolean;
  owner: string | null;
  business_owner_id: string | null;
  data_owner_id: string | null;
  measures: MetricMeasure[];
  base: string;
}

export interface MetricVersionEntry {
  version: number;
  summary: string;
  by: string | null;
  at: string;
  definition: MetricSnapshot;
  formula: string;
  definition_hash: string;
}

export interface UsageItem {
  id: string;
  title: string;
}

export interface MetricDetail extends Omit<StoreMetric, 'usage'> {
  expression: string;
  formula: string;
  measures: MetricMeasure[];
  base: string;
  synonyms: string[];
  target: number | null;
  higher_is_better: boolean;
  available_measures: string[];
  usage: { dashboards: UsageItem[]; reports: UsageItem[]; alerts: UsageItem[]; hidden: number; total: number };
  versions: MetricVersionEntry[];
  history: { action: string; by: string | null; at: string; meta: Record<string, unknown> }[];
  allowed: {
    edit: boolean;
    approve: boolean;
    certify: boolean;
    certify_blocked: string;
    revoke: boolean;
    deprecate: boolean;
    reinstate: boolean;
    restore: boolean;
  };
}

export interface CatalogModel {
  id: string;
  key: string;
  name: string;
  description: string | null;
  time_dimension: string | null;
  datasets: { id: string; name: string; label: string; table: string }[];
  dimensions: CatalogDimension[];
  measures: CatalogMeasure[];
  metrics: CatalogMetric[];
  hierarchies: { key: string; label: string; levels: string[] }[];
}

export interface SemanticModelSummary {
  id: string;
  key: string;
  name: string;
  description: string | null;
  domain: string | null;
  version: number;
  status: string;
  time_dimension: string | null;
  metrics_count: number;
  dimensions_count: number;
  measures_count: number;
  base_dataset: { id: string; label: string; row_count: number; freshness_at: string | null } | null;
  updated_at: string;
}

// ── KPIs & analysis ─────────────────────────────────────────────────────────

export interface ChangeSummary {
  change: number | null;
  change_pct: number | null;
  direction: Direction;
  sentiment: Sentiment;
}

export interface KpiCard extends ChangeSummary {
  ref: string;
  model: string;
  metric: string;
  label: string;
  description: string | null;
  format: Format;
  higher_is_better: boolean;
  value: number | null;
  previous: number | null;
  target: number | null;
  target_status: 'met' | 'missed' | null;
  sparkline: { period: string; value: number | null }[];
  sparkline_grain: Grain;
  period: Period;
  previous_period: Period;
  evidence: { current: Evidence; previous: Evidence };
  executed_at: string;
  cached: boolean;
}

export interface MemberImpact {
  member: string;
  current_value: number | null;
  previous_value: number | null;
  current_volume: number;
  previous_volume: number;
  impact: number;
  impact_share: number | null;
  volume_share: number;
  excess_impact: number;
}

export interface Driver extends MemberImpact {
  dimension: string;
  dimension_label: string;
}

export interface DimensionBreakdown {
  key: string;
  label: string;
  explained_share: number;
  member_count: number;
  members: MemberImpact[];
}

export interface Onset {
  date: string;
  before_mean: number;
  after_mean: number;
  shift_sigma: number | null;
  significant: boolean;
  series?: { date: string; value: number }[];
}

export interface RootCause extends ChangeSummary {
  ref: string;
  metric: string;
  label: string;
  format: Format;
  higher_is_better: boolean;
  current: { value: number | null; period: Period };
  previous: { value: number | null; period: Period };
  drivers: Driver[];
  dimensions: DimensionBreakdown[];
  onset: Onset | null;
  method: string;
  evidence: Evidence[];
}

export interface ForecastPoint {
  period: string;
  value: number;
  lower: number;
  upper: number;
}

export interface Forecast {
  id: string;
  metric_key: string;
  grain: Grain;
  horizon: number;
  method: string;
  history: { period: string; value: number | null }[];
  points: ForecastPoint[];
  diagnostics: {
    observations: number;
    residual_sigma: number;
    backtest_mape: number | null;
    backtest_periods: number;
    interval: number;
    seasonal_period: number | null;
    label: string;
    format: Format;
    ref: string;
    evidence: Evidence;
    horizon_key: string;
  };
  created_at: string;
}

export interface ScenarioAssumptions {
  demand_change_pct: number;
  officer_change_pct: number;
  productivity_change_pct: number;
}

export interface Scenario {
  assumptions: ScenarioAssumptions;
  baseline: {
    period: Period;
    applications: number;
    decisions: number;
    capacity: number;
    officers: number;
    utilisation: number | null;
    sla_compliance: number | null;
    avg_processing_days: number | null;
    revenue: number;
    revenue_per_application: number;
  };
  projected: {
    applications: number;
    capacity: number;
    officers: number;
    utilisation: number | null;
    sla_compliance: number | null;
    revenue: number;
    required_officers_for_target: number | null;
    additional_officers_needed: number | null;
  };
  sla_target: number;
  model: {
    intercept: number;
    slope: number;
    r_squared: number | null;
    observations: number;
    utilisation_range?: [number, number];
    method: string;
  };
  extrapolated: boolean;
  caveats: string[];
}

export interface Anomaly {
  id: string;
  semantic_model_id: string | null;
  metric_key: string;
  period: string;
  grain: Grain;
  expected: number;
  actual: number;
  lower: number | null;
  upper: number | null;
  score: number;
  method: string;
  severity: Severity;
  status: 'open' | 'investigating' | 'resolved' | 'dismissed';
  evidence: { label: string; format: Format; direction: string; higher_is_better: boolean; query?: SemanticQuery };
  created_at: string;
}

/** Insight evidence: the queries behind the statement plus how it was calculated. */
export interface InsightEvidence {
  calculation?: string;
  method?: string;
  query?: Evidence;
  queries?: { current?: Evidence; previous?: Evidence } | Evidence[];
  applications?: { current?: Evidence };
  capacity?: { current?: Evidence };
  [detail: string]: unknown;
}

export interface Insight {
  id: string;
  metric_key: string;
  kind: string;
  severity: Severity;
  title: string;
  body: string;
  evidence: InsightEvidence;
  generated_by: string;
  period_start: string | null;
  period_end: string | null;
  created_at: string;
}

// ── Home ────────────────────────────────────────────────────────────────────

export interface AttentionItem {
  kind: string;
  severity: Severity;
  title: string;
  detail: string;
  action: { label: string; link: string };
}

export interface HomeData {
  greeting: string;
  first_name: string;
  as_of: string;
  range: string;
  summary: string;
  attention: AttentionItem[];
  pulse: KpiCard[];
  pulse_error: string | null;
  insights: Insight[];
  recent_reports: { id: string; title: string; status: string; updated_at: string }[];
  favourite_dashboards: { id: string; title: string; description: string | null; is_home: boolean }[];
  unread_notifications: number;
  freshness: { data_as_of: string | null; last_sync_at: string | null; failing_sources: number };
}

// ── Dashboards ──────────────────────────────────────────────────────────────

export interface GridPosition {
  x: number;
  y: number;
  w: number;
  h: number;
}

export interface Placement extends GridPosition {
  id: string;
  section: string | null;
}

export type WidgetType =
  | 'kpi'
  | 'chart'
  | 'table'
  | 'pivot'
  | 'gauge'
  | 'insight'
  | 'text'
  | 'globe'
  | 'forecast'
  | 'anomalies'
  | 'image'
  | 'map';

/** Chart families offered by Widget Studio (`viz.type` of a chart widget). */
export type ChartType =
  'column' | 'bar' | 'line' | 'area' | 'pie' | 'funnel' | 'treemap' | 'scatter' | 'heatmap' | 'map';

/**
 * Chart subtypes. Stacking applies to column, bar and area; smoothing and steps to line;
 * donut to pie. Older widgets also use `donut` as a type and `bar` for vertical bars.
 */
export type ChartSubtype = 'classic' | 'stacked' | 'stacked100' | 'spline' | 'step' | 'donut';

/** Status a conditional colour rule assigns; rendered with the reserved status colours. */
export type RuleStatus = 'good' | 'warning' | 'critical';

export interface ColorRule {
  op: 'gt' | 'gte' | 'lt' | 'lte' | 'eq';
  value: number;
  status: RuleStatus;
}

export interface NumberFormat {
  style: 'auto' | 'number' | 'currency' | 'percent';
  /** Decimal places; 'auto' keeps the platform's executive formatting. */
  decimals: 'auto' | 0 | 1 | 2 | 3 | 4;
  abbreviate: 'auto' | 'none' | 'K' | 'M' | 'B';
}

/** A widget's visual options (Widget Studio design panel). Every field is optional. */
export interface VizOptions {
  type?: ChartType | 'donut' | 'globe' | 'table';
  subtype?: ChartSubtype;
  orientation?: 'horizontal' | 'vertical';
  legend?: { enabled: boolean; position: 'top' | 'bottom' };
  labels?: { values?: boolean; percent?: boolean };
  axes?: {
    xLabels?: boolean;
    yGrid?: boolean;
    yMin?: number | null;
    yMax?: number | null;
    yLog?: boolean;
    yTitle?: string;
  };
  markers?: boolean;
  lineWidth?: 'thin' | 'regular' | 'thick';
  /** A horizontal reference line, e.g. a target. */
  reference?: { value: number; label?: string } | null;
  target?: number;
  number?: NumberFormat;
  /** Palette slot (1–8) per series key; colour follows the series, never its rank. */
  colors?: Record<string, number>;
  /** Conditional colour for single-series bars and table values; first matching rule wins. */
  conditional?: ColorRule[];
  /** Result columns used only to compute others (a quick function's base metric) and not drawn. */
  hide?: string[];
  /** Pivot: show a grand-total row (offered for additive metrics only). */
  totals?: boolean;
  gauge?: { min?: number; max?: number; target?: number };
  // Non-chart widgets
  compare?: 'previous_period' | 'previous_year';
  horizon?: string;
  text?: string;
  fallback?: string;
}

export interface Widget {
  id: string;
  dashboard_id: string;
  type: WidgetType;
  title: string | null;
  section: string | null;
  query: SemanticQuery;
  viz: VizOptions;
  position: GridPosition;
  priority: number;
}

/** A dimension a dashboard filter can target, with the models (and so widgets) it reaches. */
export interface FilterDimension {
  key: string;
  label: string;
  type: string;
  models: string[];
}

export interface DashboardSummary {
  id: string;
  title: string;
  description: string | null;
  theme: string;
  is_home: boolean;
  visibility: string;
  updated_at: string;
  widgets_count: number;
  owner: string;
  is_favourite: boolean;
  can_edit: boolean;
}

export interface Dashboard {
  id: string;
  title: string;
  description: string | null;
  theme: string;
  filters: DashboardFilter[];
  filter_dimensions: FilterDimension[];
  /** Metrics the widgets show, offered for ranking filters. */
  filter_metrics: { key: string; label: string; models: string[] }[];
  sections: { key: string; label: string }[];
  visibility: string;
  is_home: boolean;
  widgets: Widget[];
  layouts: { desktop: Placement[]; tablet: Placement[]; mobile: Placement[] };
  can_edit: boolean;
  updated_at: string;
}

// ── Reports ─────────────────────────────────────────────────────────────────

export type ReportStatus = 'draft' | 'in_review' | 'published' | 'archived' | string;

/** Fields every computed section carries: the blueprint that produced it, or the error it hit. */
interface SectionBase {
  blueprint?: JsonObject;
  error?: string;
}

export interface SummaryContent extends SectionBase {
  paragraphs: string[];
  period: Period;
  generated_from: string;
}
export interface KpisContent extends SectionBase {
  cards: KpiCard[];
  period: Period;
}
export interface TrendContent extends SectionBase {
  metric: string;
  label: string;
  format: Format;
  grain: Grain;
  chart: 'area' | 'line';
  series: { period: string; value: number | null }[];
  target: number | null;
  caption: string | null;
}
export interface BreakdownContent extends SectionBase {
  metric: string;
  label: string;
  format: Format;
  dimension: string;
  dimension_label: string;
  rows: { member: string; value: number | null }[];
}
export interface ForecastContent extends SectionBase {
  metric: string;
  label: string;
  format: Format;
  grain: Grain;
  method: string;
  history: { period: string; value: number | null }[];
  points: ForecastPoint[];
}
export interface AnomaliesContent extends SectionBase {
  items: {
    id: string;
    metric: string;
    label: string;
    format: Format;
    period: string;
    expected: number;
    actual: number;
    score: number;
    severity: Severity;
    direction: string;
  }[];
  empty_message: string | null;
}
export interface RisksContent extends SectionBase {
  items: { severity: 'high' | 'medium'; title: string; detail: string }[];
  empty_message: string | null;
}
export interface TextContent extends SectionBase {
  markdown: string;
}
export type RootCauseContent = RootCause & SectionBase;

interface SectionOf<T extends string, C> {
  id: string;
  report_id: string;
  position: number;
  type: T;
  title: string | null;
  content: C;
}

/** A report section; `content` is computed by the API's ReportBuilder for its `type`. */
export type ReportSection =
  | SectionOf<'summary', SummaryContent>
  | SectionOf<'kpis', KpisContent>
  | SectionOf<'chart', TrendContent>
  | SectionOf<'breakdown', BreakdownContent>
  | SectionOf<'forecast', ForecastContent>
  | SectionOf<'root_cause', RootCauseContent>
  | SectionOf<'anomalies', AnomaliesContent>
  | SectionOf<'risks', RisksContent>
  | SectionOf<'text', TextContent>;

export type SectionType = ReportSection['type'];

export interface ReportVersion {
  id: string;
  version: number;
  status: ReportStatus;
  note: string | null;
  created_by: string;
  created_at: string;
}

export type ExportFormat = 'pdf' | 'pptx' | 'xlsx' | 'csv' | 'html';

export interface ReportExport {
  id: string;
  format: ExportFormat;
  status: 'queued' | 'running' | 'ready' | 'failed';
  bytes: number | null;
  error: string | null;
  created_at: string;
}

export type ScheduleFrequency = 'daily' | 'weekly' | 'monthly' | 'quarterly';

export interface ScheduleSettings {
  frequency: ScheduleFrequency;
  time_of_day: string;
  formats: ExportFormat[];
  channels: ('email' | 'push' | 'in_app')[];
}

export interface ReportSchedule extends ScheduleSettings {
  id: string;
  recipients: string[];
  is_active: boolean;
  next_run_at: string | null;
  last_run_at: string | null;
}

export interface ReportSummary {
  id: string;
  title: string;
  subtitle: string | null;
  type: string;
  status: ReportStatus;
  theme: string;
  parameters: { range?: string; period?: Period; range_label?: string } | null;
  current_version: number;
  published_at: string | null;
  updated_at: string;
  sections_count?: number;
  owner?: { id: string; name: string };
}

export interface Report extends ReportSummary {
  sections: ReportSection[];
  versions: ReportVersion[];
  exports: ReportExport[];
  schedules: ReportSchedule[];
  can_edit: boolean;
}

export interface ReportTemplate {
  id: string;
  /** Null for platform templates; set for templates an organisation authored. */
  organisation_id: string | null;
  key: string;
  name: string;
  audience: string;
  description: string | null;
  sections: JsonObject[];
  theme: string;
}

export interface ReportComparison {
  from: number;
  to: number;
  title_changed: boolean;
  changes: {
    section: string | null;
    change: 'added' | 'removed' | 'modified';
    kpis?: { label: string; format: Format; from: number | null; to: number | null }[];
    text?: { from: string[]; to: string[] };
  }[];
}

// ── Alerts & notifications ──────────────────────────────────────────────────

export interface AlertRule {
  id: string;
  name: string;
  metric_key: string;
  operator: string;
  threshold: number;
  window: string;
  filters: QueryFilter[];
  frequency_minutes: number;
  channels: string[];
  recipients: string[];
  is_active: boolean;
  last_state: string | null;
  last_value: number | null;
  last_evaluated_at: string | null;
  created_via: string;
  /** What the rule proposes when it fires, e.g. an incident; someone allowed to approve still decides. */
  actions: { kind: 'incident'; severity?: string }[];
  alerts_count: number;
  semantic_model: { id: string; key: string; name: string } | null;
  /** The watched metric's label and display format. */
  metric: { label: string; format: Format } | null;
}

/** One firing of an alert rule. */
export interface AlertEvent {
  id: string;
  alert_rule_id: string;
  value: number;
  message: string;
  /** The KPI card at the time of firing (without its sparkline) and the top driver, if found. */
  evidence: { card?: Omit<KpiCard, 'sparkline'>; driver?: Driver | null };
  fired_at: string;
  acknowledged_at: string | null;
  rule?: Pick<AlertRule, 'id' | 'name' | 'metric_key'>;
}

export interface AppNotification {
  id: string;
  type: string;
  severity: Severity;
  title: string;
  body: string;
  link: string | null;
  data: JsonObject;
  channels: string[];
  read_at: string | null;
  created_at: string;
}

// ── Data platform ───────────────────────────────────────────────────────────

export interface ConfigField {
  key: string;
  type: 'string' | 'secret' | 'integer' | string;
  required?: boolean;
}

export interface Connector {
  id: string;
  key: string;
  name: string;
  category: string;
  capabilities: string[];
  config_schema: ConfigField[];
  status: 'available' | 'planned' | string;
}

export interface IngestionRun {
  id: string;
  mode: string;
  status: string;
  records: number;
  duration_ms: number | null;
  error_count: number;
  warning_count: number;
  log: { level: string; message: string }[];
  started_at: string;
  finished_at: string | null;
}

export interface DataSource {
  id: string;
  connector_key: string;
  name: string;
  status: string;
  sync_mode: string;
  schedule: string | null;
  last_sync_at: string | null;
  last_error: string | null;
  datasets_count: number;
  last_run: IngestionRun | null;
  config_keys: string[];
  is_database: boolean;
  load_progress: SourceLoad | null;
}

export interface SourceTable {
  name: string;
  type: 'table' | 'view';
  rows: number | null;
}

export interface SourceLoad {
  status: 'queued' | 'running' | 'done';
  row_limit: number;
  started_at: string;
  finished_at: string | null;
  tables: {
    table: string;
    status: 'queued' | 'loading' | 'loaded' | 'failed';
    rows: number | null;
    dataset_id: string | null;
    error: string | null;
  }[];
}

export interface ConnectionTest {
  ok: boolean;
  message: string;
  tables?: string[];
}

/**
 * Column statistics from the profiler. Sensitive columns come back as
 * `{ redacted: true }` for viewers without data.sensitive.
 */
export interface FieldProfile {
  redacted?: boolean;
  null_count?: number;
  null_pct?: number;
  distinct?: number;
  /** Text form of the minimum/maximum (numbers and dates). */
  min?: string | null;
  max?: string | null;
  /** Only for low-cardinality text columns. */
  top_values?: { value: string; count: number }[];
  /** Values more than four standard deviations from the mean (numeric columns). */
  outliers_4sd?: number;
}

export interface DatasetField {
  id: string;
  name: string;
  label: string;
  data_type: string;
  description: string | null;
  is_sensitive: boolean;
  profile: FieldProfile | null;
}

export interface DatasetSummary {
  id: string;
  name: string;
  label: string;
  description: string | null;
  physical_schema: string;
  physical_table: string;
  /** Null until the dataset is first profiled. */
  row_count: number | null;
  freshness_at: string | null;
  profile: {
    issues: { field?: string; severity?: string; message: string }[];
    profiled_at: string | null;
    column_count: number;
  } | null;
  fields_count?: number;
  data_source: { id: string; name: string; connector_key: string; status: string; last_sync_at: string | null } | null;
}

export interface Dataset extends DatasetSummary {
  fields: DatasetField[];
}

/** First rows of a dataset; sensitive columns are masked unless the viewer may see them. */
export interface DatasetPreview {
  columns: string[];
  rows: QueryRow[];
  masked: boolean;
}

/** A generated semantic model awaiting review; every choice carries a reason. */
export interface SemanticProposal {
  key: string;
  name: string;
  description: string | null;
  base: string;
  time_dimension: string | null;
  dimensions: { key: string; label: string; field: string; type: string; sensitive: boolean }[];
  measures: { key: string; label: string; aggregation: string }[];
  metrics: {
    key: string;
    label: string;
    expression: string;
    format: Format;
    is_kpi: boolean;
    synonyms: string[];
    reason: string;
  }[];
  reasons: string[];
}

export interface UploadResult {
  run: IngestionRun;
  dataset: Dataset;
  /** One dataset per sheet of a workbook; one for CSV/JSON. */
  datasets: Dataset[];
  source: DataSource;
}

// ── Auto BI Designer ──

export type FieldRole =
  'identifier' | 'time' | 'measure' | 'dimension' | 'geography' | 'status' | 'flag' | 'text' | 'ignored';

export interface FieldUnderstanding {
  field: string;
  label: string;
  data_type: string;
  role: FieldRole;
  concept: string;
  format: string | null;
  confidence: number;
  reasons: string[];
  values?: string[];
}

export interface TrustPart {
  key: string;
  label: string;
  score: number | null;
  detail: string;
}

export interface DataTrust {
  score: number;
  grade: 'high' | 'moderate' | 'low';
  parts: TrustPart[];
  issues: { severity: string; field: string | null; message: string }[];
}

export interface AutoBiDataset {
  id: string;
  name: string;
  label: string;
  rows: number;
  is_fact: boolean;
  fields: FieldUnderstanding[];
  trust: DataTrust;
}

export interface AutoBiRelationship {
  from: string;
  to: string;
  from_dataset: string;
  to_dataset: string;
  from_label: string;
  to_label: string;
  cardinality: 'many_to_one' | 'one_to_one';
  coverage: number;
  confidence: number;
  reasons: string[];
}

export interface AutoBiKpi {
  key: string;
  label: string;
  kind: string;
  formula: string;
  expression: string;
  format: string;
  higher_is_better: boolean;
  confidence: number;
  reason: string;
  recommended: boolean;
}

export interface AutoBiWidget {
  type: string;
  title: string;
  purpose: string;
  rationale: string;
  viz: VizOptions;
  position: { x: number; y: number; w: number; h: number };
}

export type Audience = 'executive' | 'operations';

export interface AutoBiPlan {
  source: { id: string; name: string };
  model_key: string;
  fact: { name: string; label: string; rows: number };
  entity: string;
  domain: { name: string; confidence: number; evidence: string[] };
  datasets: AutoBiDataset[];
  relationships: AutoBiRelationship[];
  kpis: AutoBiKpi[];
  window: { from: string; to: string; grain: string; span_days: number } | null;
  questions: string[];
  dashboard: { audience: Audience; widgets: AutoBiWidget[] };
  report: { range: { from: string; to: string } | null; sections: { type: string; title: string }[] };
}

export interface AutoBiPublished {
  model: string;
  dashboard_id: string;
  report_id: string;
}

/** Where a metric comes from and where it is used. */
export interface MetricLineage {
  metric: { ref: string; label: string; expression: string; description: string | null; owner: string | null };
  /** Null fields when the base dataset was uploaded rather than synced from a source. */
  source: { name: string | null; connector: string | null; last_sync_at: string | null };
  table: { name: string; label: string; freshness_at: string | null; row_count: number | null };
  transformations: { field: string; transformation: string }[];
  semantic_model: { key: string; name: string; version: number };
  dashboards: { id: string; title: string }[];
  reports: { id: string; title: string; status: string }[];
}

// ── Identity & administration ───────────────────────────────────────────────

export interface RoleRef {
  key: string;
  name: string;
}

export type Experience = 'executive' | 'analyst' | 'engineer' | 'admin';

export interface Role {
  id: string;
  key: string;
  name: string;
  description: string | null;
  experience: Experience;
  is_system: boolean;
  /** Null for platform roles shared by every tenant. */
  organisation_id: string | null;
  permissions_count: number;
  users_count: number;
  permissions: { id: string; key: string }[];
}

export interface Permission {
  id: string;
  key: string;
  group: string;
  description: string | null;
}

/** Row-level access attributes; currently the countries a user may see. */
export interface DataScope {
  country_codes?: string[];
}

export interface AdminUser {
  id: string;
  name: string;
  first_name: string;
  email: string;
  title: string | null;
  status: 'active' | 'suspended' | string;
  roles: RoleRef[];
  experience: string;
  department: string | null;
  team: string | null;
  /** Null means the user sees all data. */
  data_scope: DataScope | null;
  mfa_enabled: boolean;
  last_login_at: string | null;
  must_change_password: boolean;
  department_id: string | null;
  team_id: string | null;
  /** Present on the admin list (signed-in devices). */
  active_sessions?: number;
  created_at: string | null;
}

/** An audit event shown on a person's activity timeline. */
export interface ActivityEntry {
  id: string;
  action: string;
  resource_type: string | null;
  resource_id: string | null;
  decision: string;
  result: string;
  ip_address: string | null;
  created_at: string;
}

/** The admin user detail: the person plus their sessions and recent activity. */
export interface AdminUserDetail extends AdminUser {
  sessions: Pick<Session, 'id' | 'device' | 'ip_address' | 'created_at' | 'last_used_at'>[];
  activity: ActivityEntry[];
}

export interface Department {
  id: string;
  name: string;
  parent_id: string | null;
  users_count: number;
}

/** The organisation's sign-in and account rules (enforced by the API). */
export interface SecurityPolicy {
  password_min_length: number;
  require_mfa: 'none' | 'admins' | 'all';
  allowed_email_domains: string[];
}

export type NotificationCategory = 'alert' | 'anomaly' | 'report' | 'mention' | 'data_quality';

/** A person's own settings, stored on their profile. */
export interface Preferences {
  theme?: 'dark' | 'light' | 'system';
  accent?: string;
  date_format?: 'day_month' | 'month_day' | 'iso';
  default_range?: 'last_7_days' | 'last_30_days' | 'this_month' | 'this_quarter' | 'year_to_date';
  notifications?: Partial<Record<NotificationCategory, { email?: boolean; push?: boolean }>>;
}

export interface Organisation {
  id: string;
  name: string;
  slug: string;
  plan: string;
  industry: string | null;
  currency: string;
  timezone: string;
  branding: { accent?: string; logo_text?: string };
  settings: JsonObject;
}

export interface FeatureFlag {
  key: string;
  enabled: boolean;
  description: string | null;
}

export interface HealthComponent {
  status: 'ok' | 'degraded' | 'down' | 'not_configured' | 'unknown' | string;
  latency_ms: number | null;
  detail: string;
}

export interface SystemHealth {
  components: Record<string, HealthComponent>;
  query_performance: {
    last_hour_queries: number;
    avg_ms: number;
    p95_ms: number;
    cache_hit_rate: number;
    failures: number;
  };
  checked_at: string;
}

export interface Session {
  id: string;
  device: string | null;
  ip_address: string | null;
  created_at: string;
  last_used_at: string | null;
  expires_at: string;
}

// ── Governance ──────────────────────────────────────────────────────────────

export interface AuditLog {
  id: string;
  action: string;
  resource_type: string | null;
  resource_id: string | null;
  decision: string;
  result: string;
  query_hash: string | null;
  query_sql: string | null;
  duration_ms: number | null;
  row_count: number | null;
  ip_address: string | null;
  meta: JsonObject;
  created_at: string;
  user: { id: string; name: string; email: string } | null;
}

export interface DataQualityEntry {
  id: string;
  label: string;
  table: string;
  /** Null until the dataset is first profiled. */
  row_count: number | null;
  freshness_at: string | null;
  profiled_at: string | null;
  source: { name: string; status: string; last_sync_at: string | null } | null;
  sensitive_fields: string[];
  quality_score: number;
  issues: { field?: string; severity?: string; message: string }[];
}

export interface AiGovernance {
  totals: {
    runs: number;
    succeeded: number;
    failed: number;
    refused: number;
    tokens_in: number;
    tokens_out: number;
    cost_usd: number;
    avg_latency_ms: number;
    grounded_share: number;
    feedback_positive: number;
    feedback_negative: number;
  };
  by_day: { day: string; runs: number; tokens_in: number; tokens_out: number; cost: string; latency: string }[];
  by_planner: { planner: string; runs: number }[];
  by_intent: { intent: string; runs: number }[];
  recent: {
    id: string;
    question: string;
    intent: string | null;
    status: string;
    planner: string | null;
    model: string | null;
    tokens_in: number;
    tokens_out: number;
    cost_usd: number;
    latency_ms: number;
    created_at: string;
    conversation_id: string;
    evidence: Evidence[];
  }[];
  models: {
    id: string;
    provider: string;
    model: string;
    purpose: string;
    cost_per_mtok_in: number;
    cost_per_mtok_out: number;
    is_default: boolean;
    enabled: boolean;
  }[];
  agents: {
    id: string;
    key: string;
    name: string;
    description: string;
    stage: string;
    upstream: string[];
    enabled: boolean;
  }[];
  prompts: { id: string; key: string; version: number; is_active: boolean }[];
}

// ── Search, collaboration, AI conversations ────────────────────────────────

export interface SearchResult {
  type: string;
  id: string;
  title: string;
  subtitle: string | null;
  link: string;
}

export interface Comment {
  id: string;
  body: string;
  user: { id: string; name: string; title: string | null } | null;
  created_at: string;
}

export interface ConversationSummary {
  id: string;
  title: string;
  updated_at: string;
}

// ── Data Trust Center ──

export interface TrustRow {
  id: string;
  label: string;
  source: string | null;
  connector: string | null;
  rows: number;
  score: number | null;
  previous_score: number | null;
  history: number[];
  checked_at: string | null;
  open_drift: { critical: number; warning: number; info: number };
}

export interface TrustSummary {
  datasets: number;
  average: number | null;
  attention: number;
  open_critical: number;
}

export interface DriftImpact {
  metrics: { ref: string; label: string; status: MetricStatus }[];
  dimensions: { ref: string; label: string }[];
  dashboards: UsageItem[];
  reports: UsageItem[];
  alerts: UsageItem[];
  hidden: number;
}

export interface DriftEventView {
  id: string;
  kind: string;
  column: string | null;
  severity: 'critical' | 'warning' | 'info';
  message: string;
  status: 'open' | 'acknowledged';
  detected_at: string;
  acknowledged_at: string | null;
  acknowledged_by: string | null;
  impact: DriftImpact | null;
}

export interface TrustDetail {
  id: string;
  label: string;
  table: string;
  source: { id: string; name: string; connector_key: string; last_sync_at: string | null } | null;
  rows: number;
  trust: DataTrust;
  history: { id: string; trigger: string; row_count: number; trust_score: number | null; taken_at: string }[];
  drift: DriftEventView[];
  can_manage: boolean;
}

export interface SourceImpact {
  datasets: { id: string; label: string; table: string; rows: number; drops_table: boolean }[];
  models: { key: string; name: string; metrics: number }[];
  blocking: {
    dashboards: UsageItem[];
    reports: UsageItem[];
    alerts: UsageItem[];
    models: { key: string; name: string }[];
    hidden: number;
  };
  can_delete: boolean;
}

/** Action engine: propose → approve → run → verify → audit. */
export type ActionKind = 'incident' | 'notify' | 'webhook' | 'slack' | 'teams' | 'email';
export type ActionStatus = 'proposed' | 'approved' | 'running' | 'done' | 'failed' | 'rejected' | 'cancelled';
export type IncidentSeverity = 'low' | 'medium' | 'high' | 'critical';

export interface ActionDriver {
  dimension_label: string;
  member: string;
  impact_share?: number | null;
}

export interface ActionItem {
  id: string;
  kind: ActionKind;
  title: string;
  summary: string | null;
  status: ActionStatus;
  payload: { severity?: IncidentSeverity; metric_ref?: string; assignee_id?: string; user_ids?: string[] };
  evidence: {
    card?: { label?: string; value?: number; format?: string };
    drivers?: ActionDriver[];
    checked?: string[];
  };
  source: 'manual' | 'ai' | 'alert';
  requested_by: { id: string; name: string } | null;
  decided_by: { id: string; name: string } | null;
  decided_at: string | null;
  decision_note: string | null;
  executed_at: string | null;
  verified_at: string | null;
  result: Record<string, unknown> | null;
  error: string | null;
  attempts: number;
  destination: { id: string; name: string; kind: string } | null;
  incident: { id: string; reference: string; status: string } | null;
  created_at: string;
  needs_second_person: boolean;
  can_approve: boolean;
  can_cancel: boolean;
  can_retry: boolean;
}

export interface Incident {
  id: string;
  reference: string;
  number: number;
  title: string;
  description: string | null;
  severity: IncidentSeverity;
  status: 'open' | 'investigating' | 'resolved';
  metric_ref: string | null;
  assignee: { id: string; name: string } | null;
  assignee_id: string | null;
  action_id: string | null;
  created_at: string;
  resolved_at: string | null;
}

export interface ActionDestination {
  id: string;
  name: string;
  kind: 'webhook' | 'slack' | 'teams' | 'email';
  is_active: boolean;
}
