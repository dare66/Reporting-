/** Normalised chart description, built from API/AI payloads and rendered by <app-chart>. */
export type ChartKind = 'line' | 'area' | 'bar' | 'hbar' | 'donut' | 'funnel' | 'heatmap' | 'map' | 'forecast' | 'scatter';

export interface ChartSeries { key: string; name: string; data: (number | null)[]; format: string }

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

const label = (c: any) => c.label ?? c.key;

/** Builds a spec from a /query response and a visualisation hint. */
export function specFromQuery(result: any, viz: any = {}): ChartSpec {
  const cols: any[] = result.columns ?? [];
  const rows: any[] = result.rows ?? [];
  const time = cols.find(c => c.role === 'time');
  const dims = cols.filter(c => c.role === 'dimension' && !c.key.endsWith('_code'));
  const metrics = cols.filter(c => c.role === 'metric');
  const format = metrics[0]?.format ?? 'number';
  const type = viz.type ?? (time ? 'line' : 'bar');

  if (time && dims.length && type === 'heatmap') {
    const x = [...new Set(rows.map(r => r.period))].sort();
    const y = [...new Set(rows.map(r => r[dims[0].key]))];
    const m = metrics[0].key;
    const cells = rows.map(r => [x.indexOf(r.period), y.indexOf(r[dims[0].key]), r[m]] as [number, number, number | null]);
    return { kind: 'heatmap', categories: x, series: [], format, heat: { x, y, cells }, isTime: true, grain: time.grain, partialFrom: result.meta?.partial_from };
  }
  if (time) {
    return {
      kind: type === 'bar' ? 'bar' : type === 'area' ? 'area' : 'line', isTime: true, grain: time.grain,
      categories: rows.map(r => r.period), format, partialFrom: result.meta?.partial_from, target: viz.target ?? null,
      series: metrics.map(m => ({ key: m.key, name: label(m), format: m.format, data: rows.map(r => r[m.key]) })),
    };
  }
  if (dims.length) {
    const d = dims[0].key;
    const kind: ChartKind = type === 'donut' ? 'donut' : type === 'funnel' ? 'funnel' : type === 'map' || type === 'globe' ? 'map'
      : (viz.orientation === 'horizontal' || rows.length > 6) ? 'hbar' : 'bar';
    return {
      kind, categories: rows.map(r => String(r[d])), format,
      series: metrics.map(m => ({ key: m.key, name: label(m), format: m.format, data: rows.map(r => r[m.key]) })),
    };
  }
  return { kind: 'bar', categories: [''], format, series: metrics.map(m => ({ key: m.key, name: label(m), format: m.format, data: [rows[0]?.[m.key] ?? null] })) };
}

/** Builds a spec from an AI analyst chart block. */
export function specFromBlock(b: any): ChartSpec {
  if (b.rows) {
    const keyOf = (s: any) => s.key;
    const dim = b.dimension.key;
    const vt = b.viz?.type;
    return {
      kind: vt === 'map' ? 'map' : vt === 'funnel' ? 'funnel' : (b.viz?.orientation === 'horizontal' || b.rows.length > 6) ? 'hbar' : 'bar',
      categories: b.rows.map((r: any) => String(r[dim])), format: b.series[0]?.format ?? 'number',
      series: b.series.map((s: any) => ({ key: keyOf(s), name: s.label, format: s.format, data: b.rows.map((r: any) => r[keyOf(s)]) })),
    };
  }
  const s0 = b.series[0];
  const partial = s0.points.find((p: any) => p.partial)?.period ?? null;
  return {
    kind: b.viz?.type === 'area' ? 'area' : 'line', isTime: true, categories: s0.points.map((p: any) => p.period), format: s0.format,
    partialFrom: partial, target: b.target ?? null, markers: b.markers ?? [],
    series: b.series.map((s: any) => ({ key: s.key, name: s.label, format: s.format, data: s.points.map((p: any) => p.value) })),
  };
}

export function specFromForecast(history: { period: string; value: number }[], points: any[], format: string, name: string, grain = 'month'): ChartSpec {
  return {
    kind: 'forecast', isTime: true, format, grain, categories: history.map(h => h.period),
    series: [{ key: 'actual', name, format, data: history.map(h => h.value) }],
    forecast: { categories: points.map(p => p.period), value: points.map(p => p.value), lower: points.map(p => p.lower), upper: points.map(p => p.upper) },
  };
}
