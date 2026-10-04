import type { DefaultLabelFormatterCallbackParams as LabelParams } from 'echarts';
import type { EChartsCoreOption } from 'echarts/core';
import { fmtDate, fmtWith } from '../core/format';
import { ColorRule, RuleStatus } from '../core/models';
import { ChartSeries, ChartSpec, ChartStyle } from './chart-spec';
import { GEO_ALIAS } from './geo';

/**
 * ECharts options for a ChartSpec: one builder per chart family, plus the shared
 * design-panel styling (legend, labels, axes, stacking, reference line, colours).
 * Pure functions of the spec and the theme, so they can be unit tested.
 */

/** Resolved theme: reads design tokens and the validated categorical palette. */
export interface ChartTheme {
  token: (name: string) => string;
  palette: string[];
}

type Option = EChartsCoreOption;

/** Everything a builder needs, derived once per render. */
interface Ctx {
  s: ChartSpec;
  style: ChartStyle;
  t: (name: string) => string;
  palette: string[];
  /** Formats a value in the spec's (or stacked-100 percent) format with the widget's number format. */
  fmtv: (v: unknown) => string;
  xLabel: (v: string) => string;
  base: Option & { tooltip: Record<string, unknown> };
  legend: Option | undefined;
  grid: { left: number; right: number; top: number; bottom: number; containLabel: boolean };
  valueAxis: Record<string, unknown>;
  ink2: string;
  ink3: string;
  bg: string;
}

export const STATUS_TOKEN: Record<RuleStatus, string> = { good: '--pos', warning: '--warning', critical: '--neg' };
export const STATUS_LABEL: Record<RuleStatus, string> = { good: 'Good', warning: 'Warning', critical: 'Critical' };

const LINE_WIDTH = { thin: 1.5, regular: 2, thick: 3 } as const;

/** First conditional-colour rule a value satisfies. */
export function ruleFor(value: number | null, rules: ColorRule[] | undefined): ColorRule | null {
  if (value === null || !rules?.length) return null;
  return (
    rules.find((r) =>
      r.op === 'gt'
        ? value > r.value
        : r.op === 'gte'
          ? value >= r.value
          : r.op === 'lt'
            ? value < r.value
            : r.op === 'lte'
              ? value <= r.value
              : value === r.value,
    ) ?? null
  );
}

/** Each category's values as shares of the category total (100% stacking). */
export function toShares(series: ChartSeries[]): ChartSeries[] {
  const n = series[0]?.data.length ?? 0;
  const totals = Array.from({ length: n }, (_, i) => series.reduce((a, s) => a + Math.abs(s.data[i] ?? 0), 0));
  return series.map((s) => ({
    ...s,
    format: 'percent',
    data: s.data.map((v, i) => (v === null || totals[i] === 0 ? null : Math.abs(v) / totals[i])),
  }));
}

export function buildOption(spec: ChartSpec, theme: ChartTheme): Option {
  const style = spec.style ?? {};
  // 100% stacking draws shares; the table view keeps the underlying values.
  const s: ChartSpec = style.stack === 'percent' ? { ...spec, series: toShares(spec.series), format: 'percent' } : spec;
  const t = theme.token;
  const ink2 = t('--ink-2');
  const ink3 = t('--ink-3');
  const bg = t('--bg-1');
  const number = style.stack === 'percent' ? undefined : style.number;
  const fmtv = (v: unknown) => (typeof v === 'number' ? fmtWith(v, s.format, number) : '—');
  const xLabel = (v: string) =>
    s.isTime ? fmtDate(v, s.grain === 'month' || s.grain === 'quarter' || s.grain === 'year' ? 'month' : 'short') : v;
  const showLegend = style.legend ? style.legend.enabled : s.series.length > 1;
  const legendAtBottom = style.legend?.position === 'bottom';
  const legend = showLegend
    ? {
        ...(legendAtBottom ? { bottom: 0, left: 'center' } : { top: 0, right: 0 }),
        type: 'scroll',
        icon: 'roundRect',
        itemWidth: 10,
        itemHeight: 10,
        textStyle: { color: ink2 },
      }
    : undefined;
  const axes = style.axes ?? {};
  const ctx: Ctx = {
    s,
    style,
    t,
    palette: theme.palette,
    fmtv,
    xLabel,
    ink2,
    ink3,
    bg,
    legend,
    base: {
      animationDuration: 700,
      animationEasing: 'cubicOut',
      animationDurationUpdate: 500,
      textStyle: { fontFamily: 'Inter Tight, Inter, system-ui, sans-serif', color: ink2 },
      color: theme.palette,
      tooltip: {
        backgroundColor: t('--bg-2'),
        borderColor: t('--line-2'),
        borderWidth: 1,
        padding: [8, 12],
        textStyle: { color: t('--ink-1'), fontSize: 12.5 },
        extraCssText: 'border-radius:10px;box-shadow:0 12px 32px -12px rgba(0,0,0,.5)',
      },
    },
    grid: {
      left: 8,
      right: 16,
      top: legend && !legendAtBottom ? 32 : axes.yTitle ? 24 : 14,
      bottom: legend && legendAtBottom ? 32 : 8,
      containLabel: true,
    },
    valueAxis: {
      type: axes.yLog ? 'log' : 'value',
      name: axes.yTitle || undefined,
      nameTextStyle: { color: ink3, align: 'left' },
      min: axes.yMin ?? undefined,
      max: style.stack === 'percent' ? 1 : (axes.yMax ?? undefined),
      axisLabel: { color: ink3, formatter: fmtv },
      splitLine: { show: axes.yGrid !== false, lineStyle: { color: t('--grid') } },
      axisLine: { show: false },
    },
  };

  switch (s.kind) {
    case 'pie':
    case 'donut':
      return pie(ctx);
    case 'funnel':
      return funnel(ctx);
    case 'treemap':
      return treemap(ctx);
    case 'heatmap':
      return heatmap(ctx);
    case 'map':
      return map(ctx);
    case 'scatter':
      return scatter(ctx);
    case 'gauge':
      return gauge(ctx);
    case 'hbar':
      return hbar(ctx);
    case 'bar':
      return bar(ctx);
    case 'forecast':
      return forecast(ctx);
    default:
      return line(ctx);
  }
}

/** A series' colour: its chosen palette slot, else its position. Colour follows the series, never its rank. */
function colorOf(c: Ctx, ser: ChartSeries, index: number): string {
  const slot = c.style.colors?.[ser.key];
  return c.palette[(slot ? slot - 1 : index) % c.palette.length];
}

/** Bar fill: a matching conditional rule's status colour, else the series colour. */
function barColor(c: Ctx, ser: ChartSeries, index: number, highlight: string[] = []) {
  const color = colorOf(c, ser, index);
  const rules = c.s.series.length === 1 ? c.style.conditional : undefined;
  if (!rules?.length && !highlight.length) return color;
  return (p: LabelParams) => {
    const rule = ruleFor(typeof p.value === 'number' ? p.value : null, rules);
    if (rule) return c.t(STATUS_TOKEN[rule.status]);
    return highlight.includes(p.name) ? c.t('--accent') : color;
  };
}

/** Tooltip lines name the status a conditional rule assigned, so colour is never the only signal. */
function statusTooltip(c: Ctx) {
  const rules = c.s.series.length === 1 ? c.style.conditional : undefined;
  if (!rules?.length) return {};
  return {
    formatter: (raw: LabelParams | LabelParams[]) => {
      const p = Array.isArray(raw) ? raw[0] : raw;
      const v = typeof p.value === 'number' ? p.value : null;
      const rule = ruleFor(v, rules);
      return `${p.name}<br><b>${c.fmtv(v)}</b>${rule ? ` · ${STATUS_LABEL[rule.status]}` : ''}`;
    },
  };
}

function valueLabel(c: Ctx, position: string) {
  return c.style.labels?.values
    ? { show: true, position, color: c.ink2, fontSize: 11, formatter: (p: LabelParams) => c.fmtv(p.value) }
    : undefined;
}

function referenceLine(c: Ctx, axis: 'xAxis' | 'yAxis') {
  const ref = c.style.reference ?? (c.s.target != null ? { value: c.s.target, label: 'Target' } : null);
  if (!ref) return undefined;
  return {
    silent: true,
    symbol: 'none',
    lineStyle: { color: c.ink3, type: 'dashed', width: 1 },
    label: { color: c.ink3, formatter: `${ref.label || 'Reference'} ${c.fmtv(ref.value)}`, position: 'insideEndTop' },
    data: [{ [axis]: ref.value }],
  };
}

function categoryAxis(c: Ctx, data: string[], extra: Record<string, unknown> = {}) {
  return {
    type: 'category',
    data,
    axisLabel: { show: c.style.axes?.xLabels !== false, color: c.ink3, hideOverlap: true },
    axisLine: { lineStyle: { color: c.t('--axis') } },
    axisTick: { show: false },
    ...extra,
  };
}

function pie(c: Ctx): Option {
  const { s, style } = c;
  const showLabels = style.labels?.values || style.labels?.percent;
  return {
    ...c.base,
    tooltip: { ...c.base.tooltip, trigger: 'item', valueFormatter: c.fmtv },
    legend: { bottom: 0, type: 'scroll', icon: 'circle', textStyle: { color: c.ink2 } },
    series: [
      {
        type: 'pie',
        radius: s.kind === 'donut' ? ['58%', '78%'] : [0, '74%'],
        center: ['50%', '45%'],
        itemStyle: { borderColor: c.bg, borderWidth: 2, borderRadius: 4 },
        label: showLabels
          ? {
              show: true,
              color: c.ink2,
              formatter: (p: LabelParams) =>
                [style.labels?.values ? c.fmtv(p.value) : '', style.labels?.percent ? `${p.percent}%` : '']
                  .filter(Boolean)
                  .join(' · '),
            }
          : { show: false },
        data: s.categories.map((cat, i) => ({
          name: cat,
          value: s.series[0]?.data[i],
          itemStyle: { color: c.palette[i % c.palette.length] },
        })),
      },
    ],
  };
}

function funnel(c: Ctx): Option {
  const { s, t } = c;
  const steps = [t('--seq-5'), t('--seq-4'), t('--seq-3'), t('--seq-2'), t('--seq-1')];
  return {
    ...c.base,
    tooltip: { ...c.base.tooltip, trigger: 'item', valueFormatter: c.fmtv },
    series: [
      {
        type: 'funnel',
        left: '5%',
        width: '90%',
        sort: 'descending',
        gap: 2,
        minSize: '8%',
        label: { color: t('--ink-1'), formatter: (p: LabelParams) => `${p.name}  ${c.fmtv(p.value)}` },
        itemStyle: { borderColor: c.bg, borderWidth: 0 },
        data: s.categories.map((cat, i) => ({
          name: cat.replace(/_/g, ' '),
          value: s.series[0]?.data[i],
          itemStyle: { color: steps[i] ?? steps[steps.length - 1] },
        })),
      },
    ],
  };
}

function treemap(c: Ctx): Option {
  const { s } = c;
  return {
    ...c.base,
    tooltip: { ...c.base.tooltip, trigger: 'item', valueFormatter: c.fmtv },
    series: [
      {
        type: 'treemap',
        roam: false,
        nodeClick: false,
        breadcrumb: { show: false },
        top: 0,
        left: 0,
        right: 0,
        bottom: 0,
        itemStyle: { borderColor: c.bg, borderWidth: 2, gapWidth: 2, borderRadius: 4 },
        label: {
          show: true,
          color: '#fff',
          fontSize: 12,
          formatter: (p: LabelParams) => (c.style.labels?.values ? `${p.name}\n${c.fmtv(p.value)}` : p.name),
        },
        data: s.categories
          .map((cat, i) => ({ name: cat, value: s.series[0]?.data[i] ?? 0 }))
          .map((d, i) => ({ ...d, itemStyle: { color: c.palette[i % c.palette.length] } })),
      },
    ],
  };
}

function heatmap(c: Ctx): Option {
  const { s, t } = c;
  const heat = s.heat ?? { x: [], y: [], cells: [] };
  const vals = heat.cells.map((cell) => cell[2]).filter((v): v is number => v !== null);
  const cellOf = (p: LabelParams) => (Array.isArray(p.value) ? (p.value as number[]) : [0, 0, 0]);
  return {
    ...c.base,
    tooltip: {
      ...c.base.tooltip,
      formatter: (p: LabelParams) => {
        const [x, y, v] = cellOf(p);
        return `${heat.y[y]}<br>${c.xLabel(heat.x[x])}: <b>${c.fmtv(v)}</b>`;
      },
    },
    grid: { left: 8, right: 16, top: 8, bottom: 48, containLabel: true },
    xAxis: categoryAxis(c, heat.x.map(c.xLabel), { splitArea: { show: false } }),
    yAxis: { type: 'category', data: heat.y, axisLabel: { color: c.ink2 }, axisLine: { show: false } },
    visualMap: {
      min: Math.min(...vals),
      max: Math.max(...vals),
      orient: 'horizontal',
      left: 'center',
      bottom: 0,
      itemHeight: 120,
      calculable: false,
      inRange: { color: [t('--seq-1'), t('--seq-2'), t('--seq-3'), t('--seq-4'), t('--seq-5')] },
      textStyle: { color: c.ink3 },
      formatter: c.fmtv,
    },
    series: [
      {
        type: 'heatmap',
        data: heat.cells,
        label: c.style.labels?.values
          ? { show: true, color: t('--ink-1'), fontSize: 10, formatter: (p: LabelParams) => c.fmtv(cellOf(p)[2]) }
          : undefined,
        itemStyle: { borderColor: c.bg, borderWidth: 2, borderRadius: 3 },
      },
    ],
  };
}

function map(c: Ctx): Option {
  const { s, t } = c;
  const data = s.categories.map((cat, i) => ({ name: GEO_ALIAS[cat] ?? cat, value: s.series[0]?.data[i] }));
  const vals = data.map((d) => d.value ?? 0);
  return {
    ...c.base,
    tooltip: {
      ...c.base.tooltip,
      trigger: 'item',
      formatter: (p: LabelParams) =>
        typeof p.value === 'number' && !Number.isNaN(p.value) ? `${p.name}: <b>${c.fmtv(p.value)}</b>` : p.name,
    },
    visualMap: {
      min: 0,
      max: Math.max(...vals, 1),
      left: 8,
      bottom: 8,
      itemHeight: 90,
      calculable: false,
      textStyle: { color: c.ink3 },
      formatter: c.fmtv,
      inRange: { color: [t('--seq-1'), t('--seq-2'), t('--seq-3'), t('--seq-4'), t('--seq-5')] },
    },
    series: [
      {
        type: 'map',
        map: 'world',
        roam: true,
        zoom: 1.15,
        center: [70, 20],
        emphasis: { label: { show: false }, itemStyle: { areaColor: t('--accent') } },
        itemStyle: { areaColor: t('--bg-3'), borderColor: c.bg, borderWidth: 0.6 },
        data,
      },
    ],
  };
}

function scatter(c: Ctx): Option {
  const { s } = c;
  const [xs, ys] = s.series;
  const fx = (v: unknown) => (typeof v === 'number' ? fmtWith(v, xs?.format ?? 'number') : '—');
  return {
    ...c.base,
    tooltip: {
      ...c.base.tooltip,
      trigger: 'item',
      formatter: (p: LabelParams) => {
        const [x, y] = Array.isArray(p.value) ? (p.value as number[]) : [0, 0];
        return `<b>${p.name}</b><br>${xs?.name}: ${fx(x)}<br>${ys?.name}: ${c.fmtv(y)}`;
      },
    },
    grid: { ...c.grid, bottom: 28 },
    xAxis: {
      type: 'value',
      name: xs?.name,
      nameLocation: 'middle',
      nameGap: 26,
      nameTextStyle: { color: c.ink3 },
      axisLabel: { color: c.ink3, formatter: fx },
      splitLine: { lineStyle: { color: c.t('--grid') } },
    },
    yAxis: { ...c.valueAxis, name: c.style.axes?.yTitle || ys?.name },
    series: [
      {
        type: 'scatter',
        symbolSize: 11,
        itemStyle: { color: colorOf(c, ys ?? xs, 0), borderColor: c.bg, borderWidth: 2, opacity: 0.9 },
        label: c.style.labels?.values
          ? { show: true, position: 'right', color: c.ink2, fontSize: 11, formatter: (p: LabelParams) => p.name }
          : undefined,
        markLine: referenceLine(c, 'yAxis'),
        data: s.categories.map((name, i) => ({ name, value: [xs?.data[i], ys?.data[i]] })),
      },
    ],
  };
}

function gauge(c: Ctx): Option {
  const { s, t } = c;
  const g = s.gauge ?? { min: 0, max: 1, target: null };
  const value = s.series[0]?.data[0] ?? null;
  const rule = ruleFor(value, c.style.conditional);
  const color = rule ? t(STATUS_TOKEN[rule.status]) : c.palette[0];
  const arc = { startAngle: 210, endAngle: -30, min: g.min, max: g.max, center: ['50%', '58%'], radius: '92%' };
  return {
    ...c.base,
    series: [
      {
        ...arc,
        type: 'gauge',
        progress: { show: true, width: 14, roundCap: true, itemStyle: { color } },
        axisLine: { roundCap: true, lineStyle: { width: 14, color: [[1, t('--bg-3')]] } },
        pointer: { show: false },
        axisTick: { show: false },
        splitLine: { show: false },
        axisLabel: { show: false },
        anchor: { show: false },
        title: { show: true, offsetCenter: [0, '34%'], color: c.ink3, fontSize: 12 },
        detail: {
          valueAnimation: true,
          offsetCenter: [0, '0%'],
          color: t('--ink-1'),
          fontSize: 26,
          fontWeight: 600,
          formatter: (v: number) => c.fmtv(v) + (rule ? `\n{s|${STATUS_LABEL[rule.status]}}` : ''),
          rich: { s: { fontSize: 12, color: c.ink3, padding: [6, 0, 0, 0] } },
        },
        data: [{ value, name: s.series[0]?.name ?? '' }],
      },
      ...(g.target !== null
        ? [
            {
              ...arc,
              type: 'gauge',
              axisLine: { show: false },
              progress: { show: false },
              axisTick: { show: false },
              splitLine: { show: false },
              axisLabel: { show: false },
              detail: { show: false },
              title: { show: false },
              pointer: { icon: 'rect', width: 3, length: 18, offsetCenter: [0, '-82%'], itemStyle: { color: c.ink2 } },
              tooltip: { show: false },
              data: [{ value: g.target }],
            },
          ]
        : []),
    ],
  };
}

function hbar(c: Ctx): Option {
  const { s, style } = c;
  const stacked = Boolean(style.stack);
  return {
    ...c.base,
    legend: c.legend,
    tooltip: {
      ...c.base.tooltip,
      trigger: 'axis',
      axisPointer: { type: 'shadow' },
      valueFormatter: c.fmtv,
      ...statusTooltip(c),
    },
    grid: { ...c.grid, right: 56 },
    xAxis: c.valueAxis,
    yAxis: {
      type: 'category',
      inverse: true,
      data: s.categories,
      axisLabel: { show: style.axes?.xLabels !== false, color: c.ink2, width: 160, overflow: 'truncate' },
      axisLine: { lineStyle: { color: c.t('--axis') } },
      axisTick: { show: false },
    },
    series: s.series.map((ser, si) => ({
      type: 'bar',
      name: ser.name,
      data: ser.data,
      stack: stacked ? 'total' : undefined,
      barMaxWidth: 18,
      barGap: '20%',
      itemStyle: {
        borderRadius: stacked ? 0 : [0, 4, 4, 0],
        borderColor: stacked ? c.bg : undefined,
        borderWidth: stacked ? 1 : 0,
        color: barColor(c, ser, si, s.highlight),
      },
      label:
        valueLabel(c, stacked ? 'inside' : 'right') ??
        (s.series.length === 1 && !style.labels
          ? {
              show: true,
              position: 'right',
              color: c.ink2,
              formatter: (p: LabelParams) => c.fmtv(p.value),
              fontSize: 11.5,
            }
          : undefined),
      markLine: si === 0 ? referenceLine(c, 'xAxis') : undefined,
    })),
  };
}

function bar(c: Ctx): Option {
  const { s, style } = c;
  const stacked = Boolean(style.stack);
  return {
    ...c.base,
    legend: c.legend,
    tooltip: {
      ...c.base.tooltip,
      trigger: 'axis',
      axisPointer: { type: 'shadow' },
      valueFormatter: c.fmtv,
      ...statusTooltip(c),
    },
    grid: c.grid,
    xAxis: categoryAxis(c, s.categories.map(c.xLabel)),
    yAxis: c.valueAxis,
    series: s.series.map((ser, si) => ({
      type: 'bar',
      name: ser.name,
      stack: stacked ? 'total' : undefined,
      barMaxWidth: 24,
      itemStyle: {
        borderRadius: stacked ? 0 : [4, 4, 0, 0],
        borderColor: stacked ? c.bg : undefined,
        borderWidth: stacked ? 1 : 0,
        color: barColor(c, ser, si),
      },
      label: valueLabel(c, stacked ? 'inside' : 'top'),
      markLine: si === 0 ? referenceLine(c, 'yAxis') : undefined,
      data: ser.data.map((v, i) => ({
        value: v,
        itemStyle: s.partialFrom && s.categories[i] >= s.partialFrom ? { opacity: 0.45 } : undefined,
      })),
    })),
  };
}

function forecast(c: Ctx): Option {
  const { s, t } = c;
  const f = s.forecast ?? { categories: [], value: [], lower: [], upper: [] };
  const cats = [...s.categories, ...f.categories];
  const n = s.categories.length;
  const pad = (arr: (number | null)[], before: number) => [...Array(before).fill(null), ...arr];
  const lastActual = s.series[0].data[n - 1];
  const palette = c.palette;
  return {
    ...c.base,
    legend: {
      top: 0,
      right: 0,
      textStyle: { color: c.ink2 },
      data: [s.series[0].name, 'Forecast', '80% interval'],
    },
    tooltip: { ...c.base.tooltip, trigger: 'axis', valueFormatter: c.fmtv },
    grid: { ...c.grid, top: 32 },
    xAxis: categoryAxis(c, cats.map(c.xLabel), { boundaryGap: false }),
    yAxis: c.valueAxis,
    series: [
      {
        name: s.series[0].name,
        type: 'line',
        data: s.series[0].data,
        showSymbol: false,
        lineStyle: { width: 2, color: palette[0] },
        itemStyle: { color: palette[0] },
        areaStyle: { color: palette[0], opacity: 0.08 },
      },
      {
        name: '80% interval',
        type: 'line',
        stack: 'band',
        data: pad([lastActual, ...f.lower], n - 1),
        lineStyle: { opacity: 0 },
        showSymbol: false,
        tooltip: { show: false },
      },
      {
        name: '80% interval',
        type: 'line',
        stack: 'band',
        data: pad([0, ...f.upper.map((u, i) => u - f.lower[i])], n - 1),
        lineStyle: { opacity: 0 },
        showSymbol: false,
        areaStyle: { color: t('--ai'), opacity: 0.14 },
        itemStyle: { color: t('--ai') },
        tooltip: { show: false },
      },
      {
        name: 'Forecast',
        type: 'line',
        data: pad([lastActual, ...f.value], n - 1),
        showSymbol: true,
        symbolSize: 6,
        lineStyle: { width: 2, type: [6, 4], color: t('--ai') },
        itemStyle: { color: t('--ai'), borderColor: c.bg, borderWidth: 2 },
      },
    ],
  };
}

/** Line and area charts over time or categories, with the in-progress period drawn apart. */
function line(c: Ctx): Option {
  const { s, style, t, bg } = c;
  const stacked = Boolean(style.stack);
  const width = LINE_WIDTH[style.lineWidth ?? 'regular'];
  // The in-progress period is drawn as a separate faint dashed segment so it never reads as a collapse.
  // Stacked series skip this: a gap in one layer would shift every layer above it.
  const partialFrom = stacked ? null : s.partialFrom;
  const partialIdx = partialFrom ? s.categories.findIndex((cat) => cat >= partialFrom) : -1;
  const markPoint = s.markers?.length
    ? {
        symbol: 'circle',
        symbolSize: 10,
        itemStyle: { color: t('--neg'), borderColor: bg, borderWidth: 2 },
        label: { show: false },
        data: s.markers
          .map((m) => ({
            coord: [s.categories.indexOf(m.period), s.series[0].data[s.categories.indexOf(m.period)]],
            value: m.label,
          }))
          .filter((m) => (m.coord[0] as number) >= 0),
      }
    : undefined;
  const solid = (data: (number | null)[]) => (partialIdx > 0 ? data.map((v, i) => (i < partialIdx ? v : null)) : data);
  const partial = (data: (number | null)[]) => data.map((v, i) => (partialIdx > 0 && i >= partialIdx - 1 ? v : null));
  const series: Option[] = s.series.map((ser, si) => {
    const color = colorOf(c, ser, si);
    return {
      type: 'line',
      name: ser.name,
      data: solid(ser.data),
      stack: stacked ? 'total' : undefined,
      smooth: style.smooth ?? 0.25,
      step: style.step ? 'middle' : undefined,
      showSymbol: style.markers ?? false,
      symbolSize: 8,
      connectNulls: false,
      lineStyle: { width, color },
      itemStyle: { color, borderColor: bg, borderWidth: 2 },
      areaStyle: s.kind === 'area' ? { opacity: stacked ? 0.55 : 0.1, color } : undefined,
      label: valueLabel(c, 'top'),
      markLine: si === 0 ? referenceLine(c, 'yAxis') : undefined,
      markPoint: si === 0 ? markPoint : undefined,
      endLabel: s.series.length > 1 && !stacked ? { show: true, color: c.ink2, formatter: '{a}' } : undefined,
    };
  });
  if (partialIdx > 0) {
    s.series.forEach((ser, si) => {
      const color = colorOf(c, ser, si);
      series.push({
        type: 'line',
        name: ser.name,
        data: partial(ser.data),
        smooth: style.smooth ?? 0.25,
        step: style.step ? 'middle' : undefined,
        showSymbol: true,
        symbolSize: 6,
        connectNulls: false,
        lineStyle: { width: Math.max(1, width - 0.5), type: [4, 4], color, opacity: 0.55 },
        itemStyle: { color: bg, borderColor: color, borderWidth: 1.5 },
        tooltip: { valueFormatter: (v: unknown) => `${c.fmtv(v)} (in progress)` },
        markArea:
          si === 0
            ? {
                silent: true,
                itemStyle: { color: t('--bg-3'), opacity: 0.35 },
                data: [[{ xAxis: Math.max(0, partialIdx - 0.5) }, { xAxis: s.categories.length - 1 }]],
              }
            : undefined,
      });
    });
  }
  return {
    ...c.base,
    legend: c.legend ? { ...c.legend, data: s.series.map((x) => x.name) } : undefined,
    tooltip: {
      ...c.base.tooltip,
      trigger: 'axis',
      axisPointer: { type: 'line', lineStyle: { color: t('--axis') } },
      valueFormatter: c.fmtv,
    },
    grid: c.grid,
    xAxis: categoryAxis(c, s.categories.map(c.xLabel), { boundaryGap: false }),
    yAxis: { ...c.valueAxis, scale: s.format === 'percent' && !stacked && c.style.axes?.yMin == null },
    series,
  };
}
