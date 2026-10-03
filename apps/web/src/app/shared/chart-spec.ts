import { ChartBlock } from '../core/ai-models';
import { ColorRule, ForecastPoint, NumberFormat, QueryColumn, QueryResult, QueryRow, VizOptions } from '../core/models';

/** Normalised chart description, built from API/AI payloads and rendered by <app-chart>. */
export type ChartKind =
  | 'line'
  | 'area'
  | 'bar'
  | 'hbar'
  | 'pie'
  | 'donut'
  | 'funnel'
  | 'treemap'
  | 'heatmap'
  | 'map'
  | 'forecast'
  | 'scatter'
  | 'gauge';

export interface ChartSeries {
  key: string;
  name: string;
  data: (number | null)[];
  format: string;
}

/** Design-panel options that change how a spec is drawn, not what it contains. */
export interface ChartStyle {
  stack?: 'normal' | 'percent';
  smooth?: number;
  step?: boolean;
  legend?: VizOptions['legend'];
  labels?: VizOptions['labels'];
  axes?: VizOptions['axes'];
  markers?: boolean;
  lineWidth?: VizOptions['lineWidth'];
  reference?: VizOptions['reference'];
  number?: NumberFormat;
  /** Palette slot (1–8) per series key. */
  colors?: Record<string, number>;
  conditional?: ColorRule[];
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
  gauge?: { min: number; max: number; target: number | null };
  highlight?: string[];
  style?: ChartStyle;
  /** Break-by members beyond the palette's eight series, left out (the largest eight are kept). */
  omitted?: number;
}

/** The categorical palette has eight validated hues; more series fold out rather than reuse a colour. */
export const MAX_SERIES = 8;

type Value = number | null;

const fmtOf = (c: QueryColumn) => c.format ?? 'number';
const label = (c: QueryColumn) => c.label ?? c.key;
const num = (v: QueryRow[string] | undefined): Value => (typeof v === 'number' ? v : v == null ? null : Number(v));

/** Design-panel options carried into the spec. */
function styleOf(viz: VizOptions): ChartStyle {
  const sub = viz.subtype;
  return {
    stack: sub === 'stacked' ? 'normal' : sub === 'stacked100' ? 'percent' : undefined,
    // Older widgets (no subtype) keep the gentle house curve; "classic" lines are straight.
    smooth: sub === 'spline' ? 0.5 : sub === 'classic' || sub === 'step' ? 0 : 0.25,
    step: sub === 'step',
    legend: viz.legend,
    labels: viz.labels,
    axes: viz.axes,
    markers: viz.markers,
    lineWidth: viz.lineWidth,
    reference: viz.reference ?? null,
    number: viz.number,
    colors: viz.colors,
    conditional: viz.conditional,
  };
}

/**
 * Spreads one metric across series by the members of a second column ("break by"),
 * keeping categories and members in first-appearance order (the API's sort order).
 */
export function breakBy(
  rows: QueryRow[],
  categoryKey: string,
  memberKey: string,
  metric: QueryColumn,
): { categories: string[]; series: ChartSeries[]; omitted: number } {
  const categories = [...new Set(rows.map((r) => String(r[categoryKey])))];
  const members = [...new Set(rows.map((r) => String(r[memberKey])))];
  const at = new Map(rows.map((r) => [`${String(r[categoryKey])}\u0000${String(r[memberKey])}`, num(r[metric.key])]));
  const all = members.map((m) => ({
    key: m,
    name: m,
    format: fmtOf(metric),
    data: categories.map((c) => at.get(`${c}\u0000${m}`) ?? null),
  }));
  // Keep the largest members when there are more than the palette can distinguish.
  const total = (s: ChartSeries) => s.data.reduce<number>((a, v) => a + Math.abs(v ?? 0), 0);
  const kept =
    all.length > MAX_SERIES
      ? new Set(
          [...all]
            .sort((a, b) => total(b) - total(a))
            .slice(0, MAX_SERIES)
            .map((s) => s.key),
        )
      : null;
  const series = kept ? all.filter((s) => kept.has(s.key)) : all;
  return { categories, series, omitted: all.length - series.length };
}

/** Builds a spec from a /query response and a widget's visual options. */
export function specFromQuery(result: QueryResult, viz: VizOptions = {}): ChartSpec {
  const cols = result.columns ?? [];
  const rows = result.rows ?? [];
  const hidden = new Set(viz.hide ?? []);
  const time = cols.find((c) => c.role === 'time');
  const dims = cols.filter((c) => c.role === 'dimension' && !c.key.endsWith('_code'));
  const metrics = cols.filter((c) => c.role === 'metric' && !hidden.has(c.key));
  const format = metrics[0]?.format ?? 'number';
  const type = viz.type ?? (time ? 'line' : 'bar');
  const style = styleOf(viz);
  // Nothing to draw, e.g. options that hide every column of a result still being replaced.
  if (!metrics.length) return { kind: 'bar', categories: [], series: [], format, style };
  const perMetric = () =>
    metrics.map((m) => ({ key: m.key, name: label(m), format: fmtOf(m), data: rows.map((r) => num(r[m.key])) }));

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
      style,
    };
  }
  if (type === 'scatter' && dims.length && metrics.length >= 2) {
    // One point per member: x from the first value, y from the second.
    return {
      kind: 'scatter',
      categories: rows.map((r) => String(r[dims[0].key])),
      format: metrics[1].format ?? 'number',
      series: perMetric().slice(0, 2),
      style,
    };
  }
  if (time) {
    const split = dims.length ? breakBy(rows, 'period', dims[0].key, metrics[0]) : null;
    return {
      kind: type === 'bar' || type === 'column' ? 'bar' : type === 'area' ? 'area' : 'line',
      isTime: true,
      grain: time.grain,
      categories: split ? split.categories : rows.map((r) => String(r['period'])),
      format,
      partialFrom: result.meta?.partial_from,
      target: typeof viz.target === 'number' ? viz.target : null,
      series: split ? split.series : perMetric(),
      omitted: split?.omitted,
      style,
    };
  }
  if (dims.length) {
    const kind = categoryKind(type, viz, rows.length);
    const stackable = kind === 'bar' || kind === 'hbar' || kind === 'line' || kind === 'area';
    const split = dims.length > 1 && stackable ? breakBy(rows, dims[0].key, dims[1].key, metrics[0]) : null;
    return {
      kind,
      categories: split ? split.categories : rows.map((r) => String(r[dims[0].key])),
      format,
      series: split ? split.series : perMetric(),
      omitted: split?.omitted,
      style,
    };
  }
  return {
    kind: 'bar',
    categories: [''],
    format,
    series: metrics.map((m) => ({ key: m.key, name: label(m), format: fmtOf(m), data: [num(rows[0]?.[m.key])] })),
    style,
  };
}

/** Chart kind for a categorical (non-time) axis. */
function categoryKind(type: NonNullable<VizOptions['type']>, viz: VizOptions, rows: number): ChartKind {
  switch (type) {
    case 'pie':
      return viz.subtype === 'donut' ? 'donut' : 'pie';
    case 'donut':
    case 'funnel':
    case 'treemap':
      return type;
    case 'map':
    case 'globe':
      return 'map';
    case 'column':
      return 'bar';
    case 'line':
    case 'area':
      return type;
    default:
      // "bar": horizontal unless asked otherwise; older widgets switch to horizontal for long lists.
      if (viz.orientation) return viz.orientation === 'horizontal' ? 'hbar' : 'bar';
      return rows > 6 ? 'hbar' : 'bar';
  }
}

/** A single value against a scale, for gauge widgets. */
export function specFromGauge(result: QueryResult, viz: VizOptions = {}): ChartSpec {
  const metric = result.columns.find((c) => c.role === 'metric' && !(viz.hide ?? []).includes(c.key));
  const value = metric ? num(result.rows[0]?.[metric.key]) : null;
  const format = metric?.format ?? 'number';
  const target = viz.gauge?.target ?? viz.target ?? null;
  const min = viz.gauge?.min ?? 0;
  const max = viz.gauge?.max ?? (format === 'percent' ? 1 : niceMax(Math.max(value ?? 0, target ?? 0) * 1.2));
  return {
    kind: 'gauge',
    categories: [''],
    format,
    series: metric ? [{ key: metric.key, name: label(metric), format, data: [value] }] : [],
    gauge: { min, max, target },
    style: styleOf(viz),
  };
}

/** Rounds a scale maximum up to 1, 2 or 5 × a power of ten. */
export function niceMax(v: number): number {
  if (v <= 0) return 1;
  const p = 10 ** Math.floor(Math.log10(v));
  return ([1, 2, 5, 10].find((m) => m * p >= v) ?? 10) * p;
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
