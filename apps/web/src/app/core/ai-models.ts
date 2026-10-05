/**
 * Payloads produced by the AI analyst service (services/ai): the agent trace,
 * the structured blocks rendered in the conversation, and the final result.
 * Block shapes mirror services/ai/app/agents/handlers.py and actions.py.
 */
import {
  Anomaly,
  ChangeSummary,
  DimensionBreakdown,
  Driver,
  Evidence,
  Forecast,
  ForecastPoint,
  Format,
  Grain,
  KpiCard,
  Onset,
  Period,
  QueryColumn,
  QueryRow,
  Scenario,
} from './models';

/** One agent step in the run's trace. */
export interface AgentStep {
  agent: string;
  label: string;
  status: 'done' | 'blocked' | 'failed' | string;
  detail: string;
  ms: number;
}

/** Why the visualisation agent chose a chart. */
export interface VizChoice {
  type: string;
  reason?: string;
  orientation?: 'horizontal' | 'vertical';
}

export interface BlockSeries {
  key: string;
  label: string;
  format: Format;
  points?: { period: string; value: number | null; partial?: boolean }[];
}

export interface KpisBlock {
  type: 'kpis';
  title: string;
  period: Period | null;
  cards: KpiCard[];
}

/** A trend (series with points) or a breakdown (rows by one dimension). */
export interface ChartBlock {
  type: 'chart';
  title: string;
  viz: VizChoice;
  series: BlockSeries[];
  target?: number | null;
  markers?: { period: string; label: string; kind?: string }[];
  query_hash?: string;
  dimension?: { key: string; label: string };
  columns?: QueryColumn[];
  rows?: QueryRow[];
}

export interface TableBlock {
  type: 'table';
  title: string;
  viz: VizChoice;
  dimension: { key: string; label: string };
  columns: QueryColumn[];
  rows: QueryRow[];
  series: BlockSeries[];
}

/** What a driver tree needs; root-cause results and AI driver blocks both satisfy it. */
export interface DriversData extends ChangeSummary {
  label: string;
  format: Format;
  current: { value: number | null; period: Period };
  previous: { value: number | null; period: Period };
  drivers: Driver[];
  dimensions: DimensionBreakdown[];
  onset: Onset | null;
  method: string;
}

export interface DriversBlock extends DriversData {
  type: 'drivers';
  title: string;
  metric: string;
}

export interface ForecastBlock {
  type: 'forecast';
  title: string;
  metric: string;
  format: Format;
  grain: Grain;
  history: { period: string; value: number | null }[];
  points: ForecastPoint[];
  method: string;
  diagnostics: Forecast['diagnostics'];
}

export type ScenarioBlock = Scenario & { type: 'scenario'; title: string };

export interface ReportBlock {
  type: 'report';
  title: string;
  report_id: string;
  status: string;
  version: number | null;
  sections: { id: string; type: string; title: string | null; error: string | null }[];
  note: string | null;
}

export interface AlertBlock {
  type: 'alert';
  title: string;
  rule: {
    id: string;
    name: string;
    metric: string;
    condition: string;
    window: string;
    frequency: string;
    channels: string[];
  };
}

export interface DashboardBlock {
  type: 'dashboard';
  title: string;
  dashboard_id: string;
  widgets: { type: string; title: string }[];
}

export interface AnomaliesBlock {
  type: 'anomalies';
  title: string;
  items: Anomaly[];
}

export interface CapabilitiesBlock {
  type: 'capabilities';
  items: { title: string; example: string }[];
}

/** Stored with each assistant message so a reopened conversation shows the same trace and evidence. */
export interface MetaBlock {
  type: 'meta';
  suggestions: string[];
  evidence: Evidence[];
  trace: AgentStep[];
  planner: string | null;
  narrator: string | null;
}

/** An action the analyst proposed; it runs only once someone allowed to approve it does. */
export interface ActionBlock {
  type: 'action';
  title: string;
  action: { id: string; kind: string; title: string; summary: string; severity: string; status: string };
  note: string;
}

export type AiBlock =
  | KpisBlock
  | ChartBlock
  | TableBlock
  | DriversBlock
  | ForecastBlock
  | ScenarioBlock
  | ReportBlock
  | AlertBlock
  | ActionBlock
  | DashboardBlock
  | AnomaliesBlock
  | CapabilitiesBlock;

/** The final result of a run (streamed as `done`), plus the ids under which it was saved. */
export interface ChatResult {
  answer: string;
  headline: string;
  blocks: AiBlock[];
  evidence: Evidence[];
  suggestions: string[];
  caveats: string[];
  intent: string | null;
  planner: string | null;
  narrator: string | null;
  trace: AgentStep[];
  status: 'succeeded' | 'refused' | 'failed';
  conversation_id?: string;
  run_id?: string;
}

export interface ConversationMessage {
  id: string;
  role: 'user' | 'assistant';
  content: string;
  blocks: (AiBlock | MetaBlock)[] | null;
  run_id: string | null;
}

export interface Conversation {
  id: string;
  title: string;
  updated_at: string;
  messages: ConversationMessage[];
}
