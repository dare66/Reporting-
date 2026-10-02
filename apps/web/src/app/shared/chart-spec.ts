import { ChartBlock } from '../core/ai-models';
import { ForecastPoint, QueryColumn, QueryResult, QueryRow } from '../core/models';

/** Normalised chart description, built from API/AI payloads and rendered by <app-chart>. */
export type ChartKind =
  'line' | 'area' | 'bar' | 'hbar' | 'donut' | 'funnel' | 'heatmap' | 'map' | 'forecast' | 'scatter';

export interface ChartSeries {
  key: string;
  name: string;
  data: (number | null)[];
  format: string;
}

export interface ChartSpec {
  kind: ChartKind;
  categories: string[];
  series: ChartSeries[];
  format: string;
  isTime?: boolean;
  grain?: string;
  partialFrom?: string | null;
  target?: number | null;
  markers?: { period: string; label: string }[];
  forecast?: { categories: string[]; value: number[]; lower: number[]; upper: number[] };
  heat?: { x: string[]; y: string[]; cells: [number, number, number | null][] };
  highlight?: string[];
}

/** Visualisation hint: a widget's or explorer's chosen type plus options. */
export interface VizHint {
  type?: unknown;
  orientation?: unknown;
  target?: unknown;
}

type Value = number | null;

const fmtOf = (c: QueryColumn) => c.format ?? 'number';
const label = (c: QueryColumn) => c.label ?? c.key;
const num = (v: QueryRow[string] | undefined): Value => (typeof v === 'number' ? v : v == null ? null : Number(v));

/** Builds a spec from a /query response and a visualisation hint. */
export function specFromQuery(result: QueryResult, viz: VizHint = {}): ChartSpec {
  const cols = result.columns ?? [];
  const rows = result.rows ?? [];
  const time = cols.find((c) => c.role === 'time');
  const dims = cols.filter((c) => c.role === 'dimension' && !c.key.endsWith('_code'));
  const metrics = cols.filter((c) => c.role === 'metric');
  const format = metrics[0]?.format ?? 'number';
  const type = typeof viz.type === 'string' ? viz.type : time ? 'line' : 'bar';

  if (time && dims.length && type === 'heatmap') {
    const x = [...new Set(rows.map((r) => String(r['period'])))].sort();
    const y = [...new Set(rows.map((r) => String(r[dims[0].key])))];
    const m = metrics[0].key;
    const cells = rows.map(
      (r) => [x.indexOf(String(r['period'])), y.indexOf(String(r[dims[0].key])), num(r[m])] as [number, number, Value],
    );
    return {
      kind: 'heatmap',
      categories: x,
      series: [],
      format,
      heat: { x, y, cells },
      isTime: true,
      grain: time.grain,
      partialFrom: result.meta?.partial_from,
    };
  }
  if (time) {
    const target = typeof viz.target === 'number' ? viz.target : null;
    return {
      kind: type === 'bar' ? 'bar' : type === 'area' ? 'area' : 'line',
      isTime: true,
      grain: time.grain,
      categories: rows.map((r) => String(r['period'])),
      format,
      partialFrom: result.meta?.partial_from,
      target,
      series: metrics.map((m) => ({
        key: m.key,
        name: label(m),
        format: fmtOf(m),
        data: rows.map((r) => num(r[m.key])),
      })),
    };
  }
  if (dims.length) {
    const d = dims[0].key;
    const kind: ChartKind =
      type === 'donut'
        ? 'donut'
        : type === 'funnel'
          ? 'funnel'
          : type === 'map' || type === 'globe'
            ? 'map'
            : viz.orientation === 'horizontal' || rows.length > 6
              ? 'hbar'
              : 'bar';
    return {
      kind,
      categories: rows.map((r) => String(r[d])),
      format,
      series: metrics.map((m) => ({
        key: m.key,
        name: label(m),
        format: fmtOf(m),
        data: rows.map((r) => num(r[m.key])),
      })),
    };
  }
  return {
    kind: 'bar',
    categories: [''],
    format,
    series: metrics.map((m) => ({ key: m.key, name: label(m), format: fmtOf(m), data: [num(rows[0]?.[m.key])] })),
  };
}

/** Builds a spec from an AI analyst chart block. */
export function specFromBlock(b: ChartBlock): ChartSpec {
  const rows = b.rows;
  if (rows && b.dimension) {
    const dim = b.dimension.key;
    const vt = b.viz?.type;
    return {
      kind:
        vt === 'map'
          ? 'map'
          : vt === 'funnel'
            ? 'funnel'
            : b.viz?.orientation === 'horizontal' || rows.length > 6
              ? 'hbar'
              : 'bar',
      categories: rows.map((r) => String(r[dim])),
      format: b.series[0]?.format ?? 'number',
      series: b.series.map((s) => ({
        key: s.key,
        name: s.label,
        format: s.format,
        data: rows.map((r) => num(r[s.key])),
      })),
    };
  }
  const points = b.series[0]?.points ?? [];
  const partial = points.find((p) => p.partial)?.period ?? null;
  return {
    kind: b.viz?.type === 'area' ? 'area' : 'line',
    isTime: true,
    categories: points.map((p) => p.period),
    format: b.series[0]?.format ?? 'number',
    partialFrom: partial,
    target: b.target ?? null,
    markers: b.markers ?? [],
    series: b.series.map((s) => ({
      key: s.key,
      name: s.label,
      format: s.format,
      data: (s.points ?? []).map((p) => p.value),
    })),
  };
}

/** Actual history followed by the projection and its interval band. */
export function specFromForecast(
  history: { period: string; value: Value }[],
  points: ForecastPoint[],
  format: string,
  name: string,
  grain = 'month',
): ChartSpec {
  return {
    kind: 'forecast',
    isTime: true,
    format,
    grain,
    categories: history.map((h) => h.period),
    series: [{ key: 'actual', name, format, data: history.map((h) => h.value) }],
    forecast: {
      categories: points.map((p) => p.period),
      value: points.map((p) => p.value),
      lower: points.map((p) => p.lower),
      upper: points.map((p) => p.upper),
    },
  };
}
