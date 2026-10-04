import {
  Calculation,
  CatalogModel,
  ChartSubtype,
  ChartType,
  Grain,
  HavingFilter,
  QueryFilter,
  QuickFunction,
  SemanticQuery,
  VizOptions,
  Widget,
  WidgetType,
} from '../../../core/models';

/**
 * Widget Studio's model: the chart catalogue (which fields each chart takes),
 * the editable draft, and its translation to and from a stored widget.
 * Framework-free so every rule is unit tested (studio-model.spec.ts).
 * Design: docs/design/widget-studio.md §2.
 */

/** What the studio can build. Chart families map to `chart` widgets; the rest are widget types of their own. */
export type StudioKind = ChartType | 'kpi' | 'gauge' | 'table' | 'pivot';

/** Marker for "the model's time dimension at the chosen grain" in a dimension well. */
export const TIME = '__time__';

/** The wells of the data panel. Their labels change per chart (Category / X axis / Rows…). */
export type WellKey = 'category' | 'breakBy' | 'values';

export interface WellDef {
  key: WellKey;
  label: string;
  /** dimension: any dimension; time: only the time axis; either: a dimension or the time axis. */
  accepts?: 'dimension' | 'time' | 'either';
  min: number;
  max: number;
  hint?: string;
}

export interface KindDef {
  kind: StudioKind;
  label: string;
  icon: string;
  widgetType: WidgetType;
  subtypes: { key: ChartSubtype; label: string }[];
  wells: WellDef[];
}

const STACKING: KindDef['subtypes'] = [
  { key: 'classic', label: 'Classic' },
  { key: 'stacked', label: 'Stacked' },
  { key: 'stacked100', label: '100%' },
];
const SERIES_WELLS: WellDef[] = [
  { key: 'category', label: 'Category', accepts: 'either', min: 1, max: 1 },
  { key: 'values', label: 'Values', min: 1, max: 8 },
  {
    key: 'breakBy',
    label: 'Break by',
    accepts: 'dimension',
    min: 0,
    max: 1,
    hint: 'Splits a single value into series',
  },
];
const ONE_VALUE = (category: string, accepts: WellDef['accepts'] = 'dimension'): WellDef[] => [
  { key: 'category', label: category, accepts, min: 1, max: 1 },
  { key: 'values', label: 'Value', min: 1, max: 1 },
];

export const KINDS: KindDef[] = [
  { kind: 'column', label: 'Column', icon: 'column', widgetType: 'chart', subtypes: STACKING, wells: SERIES_WELLS },
  { kind: 'bar', label: 'Bar', icon: 'barh', widgetType: 'chart', subtypes: STACKING, wells: SERIES_WELLS },
  {
    kind: 'line',
    label: 'Line',
    icon: 'line',
    widgetType: 'chart',
    subtypes: [
      { key: 'classic', label: 'Straight' },
      { key: 'spline', label: 'Spline' },
      { key: 'step', label: 'Step' },
    ],
    wells: SERIES_WELLS.map((w) => (w.key === 'category' ? { ...w, label: 'X axis' } : w)),
  },
  {
    kind: 'area',
    label: 'Area',
    icon: 'area',
    widgetType: 'chart',
    subtypes: STACKING,
    wells: SERIES_WELLS.map((w) => (w.key === 'category' ? { ...w, label: 'X axis' } : w)),
  },
  {
    kind: 'pie',
    label: 'Pie',
    icon: 'pie',
    widgetType: 'chart',
    subtypes: [
      { key: 'classic', label: 'Pie' },
      { key: 'donut', label: 'Donut' },
    ],
    wells: ONE_VALUE('Category'),
  },
  { kind: 'funnel', label: 'Funnel', icon: 'funnel', widgetType: 'chart', subtypes: [], wells: ONE_VALUE('Stage') },
  {
    kind: 'treemap',
    label: 'Treemap',
    icon: 'treemap',
    widgetType: 'chart',
    subtypes: [],
    wells: ONE_VALUE('Category'),
  },
  {
    kind: 'scatter',
    label: 'Scatter',
    icon: 'scatter',
    widgetType: 'chart',
    subtypes: [],
    wells: [
      { key: 'category', label: 'Point', accepts: 'dimension', min: 1, max: 1, hint: 'One point per member' },
      { key: 'values', label: 'X value · Y value', min: 2, max: 2 },
    ],
  },
  {
    kind: 'heatmap',
    label: 'Heatmap',
    icon: 'heatmap',
    widgetType: 'chart',
    subtypes: [],
    wells: [
      { key: 'category', label: 'Columns (time)', accepts: 'time', min: 1, max: 1 },
      { key: 'breakBy', label: 'Rows', accepts: 'dimension', min: 1, max: 1 },
      { key: 'values', label: 'Value', min: 1, max: 1 },
    ],
  },
  { kind: 'map', label: 'Map', icon: 'globe', widgetType: 'chart', subtypes: [], wells: ONE_VALUE('Country') },
  {
    kind: 'kpi',
    label: 'Indicator',
    icon: 'kpi',
    widgetType: 'kpi',
    subtypes: [],
    wells: [{ key: 'values', label: 'Values', min: 1, max: 4, hint: 'Each value becomes a card with its comparison' }],
  },
  {
    kind: 'gauge',
    label: 'Gauge',
    icon: 'gauge',
    widgetType: 'gauge',
    subtypes: [],
    wells: [{ key: 'values', label: 'Value', min: 1, max: 1 }],
  },
  {
    kind: 'table',
    label: 'Table',
    icon: 'table',
    widgetType: 'table',
    subtypes: [],
    wells: [
      { key: 'category', label: 'Group by', accepts: 'either', min: 0, max: 1 },
      { key: 'breakBy', label: 'Then by', accepts: 'dimension', min: 0, max: 1 },
      { key: 'values', label: 'Values', min: 1, max: 8 },
    ],
  },
  {
    kind: 'pivot',
    label: 'Pivot',
    icon: 'pivot',
    widgetType: 'pivot',
    subtypes: [],
    wells: [
      { key: 'category', label: 'Rows', accepts: 'dimension', min: 1, max: 1 },
      { key: 'breakBy', label: 'Columns', accepts: 'either', min: 0, max: 1 },
      { key: 'values', label: 'Values', min: 1, max: 4 },
    ],
  },
];

export const kindDef = (kind: StudioKind): KindDef => KINDS.find((k) => k.kind === kind) ?? KINDS[0];
export const well = (kind: StudioKind, key: WellKey): WellDef | undefined =>
  kindDef(kind).wells.find((w) => w.key === key);

// ── Quick functions ─────────────────────────────────────────────────────────

export const QUICK_FUNCTIONS: { fn: QuickFunction; label: string }[] = [
  { fn: 'percent_of_total', label: '% of total' },
  { fn: 'running_sum', label: 'Running total' },
  { fn: 'year_to_date', label: 'Year to date' },
  { fn: 'difference', label: 'Change vs previous' },
  { fn: 'percent_change', label: '% change vs previous' },
  { fn: 'moving_average', label: 'Moving average' },
  { fn: 'rank', label: 'Rank' },
];

const NEEDS_TIME: QuickFunction[] = ['running_sum', 'year_to_date', 'difference', 'percent_change', 'moving_average'];
const NEEDS_ADDITIVE: QuickFunction[] = ['percent_of_total', 'running_sum', 'year_to_date'];

// ── Draft ───────────────────────────────────────────────────────────────────

export interface ValueField {
  metric: string;
  fn?: QuickFunction;
  window?: number;
}

export interface StudioDraft {
  title: string;
  kind: StudioKind;
  subtype?: ChartSubtype;
  model: string;
  /** A dimension key, TIME, or null. */
  category: string | null;
  /** A dimension key, TIME (pivot columns), or null. */
  breakBy: string | null;
  grain: Grain;
  values: ValueField[];
  range: string;
  filters: QueryFilter[];
  having: HavingFilter[];
  /** One sort, like Sisense: a value key (metric or metric__fn) or a dimension key. */
  sort: { key: string; dir: 'asc' | 'desc' } | null;
  limit: number | null;
  /** Design-panel options; type, subtype and hide are derived on save. */
  viz: VizOptions;
}

export const valueKey = (v: ValueField): string => (v.fn ? `${v.metric}__${v.fn}` : v.metric);

export function emptyDraft(model: string): StudioDraft {
  return {
    title: '',
    kind: 'column',
    subtype: 'classic',
    model,
    category: null,
    breakBy: null,
    grain: 'month',
    values: [],
    range: 'last_12_months',
    filters: [],
    having: [],
    sort: null,
    limit: null,
    viz: {},
  };
}

/** Whether a well of this chart takes the field (a dimension key or TIME). */
function accepts(def: KindDef, key: WellKey, value: string | null): boolean {
  const w = def.wells.find((x) => x.key === key);
  if (!w || value === null) return false;
  return value === TIME ? w.accepts !== 'dimension' : w.accepts !== 'time';
}

/** Switches chart family, keeping every field the new wells accept and reporting what was dropped. */
export function changeKind(d: StudioDraft, kind: StudioKind): { draft: StudioDraft; dropped: string[] } {
  const def = kindDef(kind);
  const dropped: string[] = [];
  const fits = (key: WellKey, value: string | null): string | null => {
    if (value === null) return null;
    const ok = accepts(def, key, value);
    if (!ok) dropped.push(key === 'category' ? 'category' : 'break by');
    return ok ? value : null;
  };
  // When the fields only fit the other way round (time × channel → pivot rows × columns), swap them.
  const swap =
    !accepts(def, 'category', d.category) && accepts(def, 'category', d.breakBy) && accepts(def, 'breakBy', d.category);
  const [fromCategory, fromBreakBy] = swap ? [d.breakBy, d.category] : [d.category, d.breakBy];
  let category = fits('category', fromCategory);
  // A heatmap's columns are always time.
  if (kind === 'heatmap' && category === null) category = TIME;
  const breakBy = fits('breakBy', fromBreakBy);
  const valuesWell = def.wells.find((w) => w.key === 'values');
  const kept = d.values.slice(0, valuesWell?.max ?? 0);
  if (kept.length < d.values.length) dropped.push(`${d.values.length - kept.length} value(s)`);
  // Quick functions that lost what they need (a time axis, a dimension to rank) fall back to the plain value.
  const hasTime = category === TIME || breakBy === TIME;
  const hasDimension = [category, breakBy].some((x) => x !== null && x !== TIME);
  const values = kept.map((v) => {
    const fits = !v.fn || ((!NEEDS_TIME.includes(v.fn) || hasTime) && (v.fn !== 'rank' || hasDimension));
    if (fits) return v;
    dropped.push(
      `${QUICK_FUNCTIONS.find((q) => q.fn === v.fn)?.label.toLowerCase() ?? v.fn} on ${v.metric.replace(/_/g, ' ')}`,
    );
    return { metric: v.metric };
  });
  return {
    draft: {
      ...d,
      kind,
      subtype: def.subtypes.some((s) => s.key === d.subtype) ? d.subtype : def.subtypes[0]?.key,
      category,
      breakBy,
      values,
    },
    dropped,
  };
}

/** Why a quick function cannot apply to this value here, or null when it can. */
export function functionBlocker(
  d: StudioDraft,
  metric: string,
  fn: QuickFunction,
  catalog: CatalogModel,
): string | null {
  const m = catalog.metrics.find((x) => x.key === metric);
  const hasTime = d.category === TIME || d.breakBy === TIME;
  const hasDimension = [d.category, d.breakBy].some((x) => x !== null && x !== TIME);
  if (NEEDS_TIME.includes(fn) && !hasTime) return 'Needs a time axis';
  if (fn === 'year_to_date' && d.grain === 'year') return 'Needs a grain finer than a year';
  if (fn === 'rank' && !hasDimension) return 'Needs a dimension to rank';
  if (NEEDS_ADDITIVE.includes(fn) && m && !m.additive)
    return `${m.label} is a ratio or average, so it cannot be added up`;
  return null;
}

/** Problems that stop the draft from being previewed or saved, in plain language. */
export function problems(d: StudioDraft): string[] {
  const out: string[] = [];
  const def = kindDef(d.kind);
  for (const w of def.wells) {
    const count = w.key === 'values' ? d.values.length : (w.key === 'category' ? d.category : d.breakBy) ? 1 : 0;
    if (count >= w.min) continue;
    if (w.key !== 'values') out.push(`Add a field to ${w.label}`);
    else out.push(w.min === 1 ? 'Add a value' : `Add ${w.min} values (${w.label})`);
  }
  if (d.breakBy && d.values.length > 1 && def.widgetType === 'chart')
    out.push('Break by splits one value into series — keep a single value');
  if (d.category && d.category === d.breakBy) out.push('Use different fields for category and break by');
  return out;
}

/** The governed query the draft runs. */
export function toQuery(d: StudioDraft): SemanticQuery {
  const dimensions = [d.category, d.breakBy].filter((x): x is string => x !== null && x !== TIME);
  const hasTime = d.category === TIME || d.breakBy === TIME;
  const metrics = [...new Set(d.values.map((v) => v.metric))];
  const calculations = d.values.flatMap(({ metric, fn, window }): Calculation[] =>
    !fn ? [] : fn === 'moving_average' ? [{ fn, metric, window: window ?? 3 }] : [{ fn, metric }],
  );
  const q: SemanticQuery = { model: d.model, metrics, dimensions, time: { range: d.range } };
  if (hasTime && d.kind !== 'kpi' && d.kind !== 'gauge') q.time = { ...q.time, grain: d.grain };
  if (calculations.length) q.calculations = calculations;
  if (d.filters.length) q.filters = d.filters;
  if (d.having.length) q.having = d.having;
  if (d.sort) q.sort = [d.sort];
  if (d.limit) q.limit = d.limit;
  return q;
}

/** Visual options saved with the widget: the design panel plus what the data panel implies. */
export function toViz(d: StudioDraft): VizOptions {
  const viz: VizOptions = { ...d.viz };
  // A metric only used as the base of quick functions is computed, not drawn.
  const hide = [...new Set(d.values.map((v) => v.metric))].filter((m) =>
    d.values.filter((v) => v.metric === m).every((v) => v.fn),
  );
  if (hide.length) viz.hide = hide;
  else delete viz.hide;
  if (kindDef(d.kind).widgetType === 'chart') {
    viz.type = d.kind as ChartType;
    viz.subtype = d.subtype;
    if (d.kind === 'bar') viz.orientation = 'horizontal';
    else delete viz.orientation;
  }
  return viz;
}

/** Widget payload for create/update. */
export function toWidget(d: StudioDraft): { type: WidgetType; title: string; query: SemanticQuery; viz: VizOptions } {
  return { type: kindDef(d.kind).widgetType, title: d.title.trim() || autoTitle(d), query: toQuery(d), viz: toViz(d) };
}

/** "Applications by country" — a sensible title until the author writes one. */
export function autoTitle(d: StudioDraft, catalog?: CatalogModel): string {
  const metric = (k: string) => catalog?.metrics.find((m) => m.key === k)?.label ?? k.replace(/_/g, ' ');
  const dim = (k: string) =>
    k === TIME ? 'time' : (catalog?.dimensions.find((x) => x.key === k)?.label ?? k.replace(/_/g, ' ')).toLowerCase();
  const head = d.values.map((v) => metric(v.metric)).join(' & ') || 'New widget';
  const by = [d.category, d.breakBy].filter((x): x is string => x !== null).map(dim);
  return by.length ? `${head} by ${by.join(' and ')}` : head;
}

/** Rebuilds the draft from a stored widget, so any studio-built widget can be edited again. */
export function fromWidget(w: Widget): StudioDraft {
  const q = w.query;
  const viz = w.viz ?? {};
  const kind = kindOf(w);
  const dims = (q.dimensions ?? []).filter((k) => !k.endsWith('_code'));
  const grain = q.time?.grain;
  let category: string | null;
  let breakBy: string | null;
  if (grain && kind === 'pivot' && dims[0]) [category, breakBy] = [dims[0], TIME];
  else if (grain) [category, breakBy] = [TIME, dims[0] ?? null];
  else [category, breakBy] = [dims[0] ?? null, dims[1] ?? null];

  const hidden = new Set(viz.hide ?? []);
  const values: ValueField[] = [
    ...(q.metrics ?? []).filter((m) => !hidden.has(m)).map((metric) => ({ metric })),
    ...(q.calculations ?? []).map((c) => ({ metric: c.metric, fn: c.fn, ...(c.window ? { window: c.window } : {}) })),
  ];
  // The data panel and chart picker own these; the rest is the design panel's.
  const { type, subtype, orientation, hide, ...design } = viz;

  return {
    title: w.title ?? '',
    kind,
    subtype: viz.type === 'donut' ? 'donut' : (viz.subtype ?? kindDef(kind).subtypes[0]?.key),
    model: q.model,
    category,
    breakBy,
    grain: grain ?? 'month',
    values,
    range: typeof q.time?.range === 'string' ? q.time.range : 'last_12_months',
    filters: q.filters ?? [],
    having: q.having ?? [],
    sort: q.sort?.[0] ?? null,
    limit: q.limit ?? null,
    viz: design,
  };
}

function kindOf(w: Widget): StudioKind {
  if (w.type === 'kpi' || w.type === 'gauge' || w.type === 'table' || w.type === 'pivot') return w.type;
  const t = w.viz?.type;
  if (t === 'donut') return 'pie';
  if (t === 'bar') return w.viz.orientation === 'horizontal' ? 'bar' : 'column';
  if (t && t !== 'globe' && t !== 'table') return t;
  return w.query.time?.grain ? 'line' : 'column';
}

/** Widgets the studio can open: the ones it can also build. */
export const isStudioWidget = (w: Widget) => ['chart', 'kpi', 'gauge', 'table', 'pivot'].includes(w.type);
