import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  OnDestroy,
  afterNextRender,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import type { DefaultLabelFormatterCallbackParams as LabelParams } from 'echarts';
import * as echarts from 'echarts/core';
import { BarChart, FunnelChart, HeatmapChart, LineChart, MapChart, PieChart, ScatterChart } from 'echarts/charts';
import {
  GridComponent,
  LegendComponent,
  MarkAreaComponent,
  MarkLineComponent,
  MarkPointComponent,
  TooltipComponent,
  VisualMapComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { feature } from 'topojson-client';
import type { GeometryCollection, Topology } from 'topojson-specification';
import { Theme } from '../core/theme.service';
import { fmt, fmtDate } from '../core/format';
import { ChartSpec } from './chart-spec';
import { Icon } from './icon';
import { GEO_ALIAS } from './geo';

echarts.use([
  LineChart,
  BarChart,
  PieChart,
  FunnelChart,
  HeatmapChart,
  MapChart,
  ScatterChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  MarkLineComponent,
  MarkAreaComponent,
  MarkPointComponent,
  VisualMapComponent,
  CanvasRenderer,
]);

let worldReady: Promise<void> | null = null;
export function ensureWorldMap(): Promise<void> {
  worldReady ??= fetch('geo/countries-110m.json')
    .then((r) => r.json() as Promise<Topology<{ countries: GeometryCollection }>>)
    .then((topo) => {
      // topojson's FeatureCollection and ECharts' GeoJSON input describe the same structure.
      const geo = feature(topo, topo.objects.countries) as unknown as Parameters<typeof echarts.registerMap>[1];
      echarts.registerMap('world', geo);
    });
  return worldReady;
}

@Component({
  selector: 'app-chart',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  templateUrl: './chart.html',
  host: { '[class.has-toggle]': 'tableToggle()' },
  styleUrl: './chart.scss',
})
export class Chart implements OnDestroy {
  readonly spec = input.required<ChartSpec>();
  readonly height = input(280);
  readonly tableToggle = input(true);
  readonly ariaLabel = input('chart');
  /** A category the viewer clicked (bar, slice or map region). */
  readonly categorySelect = output<{ category: string; value: number | null }>();
  readonly showTable = signal(false);

  private host = viewChild.required<ElementRef<HTMLDivElement>>('host');
  private theme = inject(Theme);
  private chart?: echarts.ECharts;
  private ro?: ResizeObserver;

  constructor() {
    afterNextRender(() => {
      this.chart = echarts.init(this.host().nativeElement, undefined, { renderer: 'canvas' });
      this.chart.on('click', (p) => {
        const raw = Array.isArray(p.value) ? p.value[2] : p.value;
        this.categorySelect.emit({ category: p.name, value: typeof raw === 'number' ? raw : null });
      });
      this.ro = new ResizeObserver(() => this.chart?.resize());
      this.ro.observe(this.host().nativeElement);
      this.render();
    });
    effect(() => {
      this.spec();
      this.theme.version();
      this.render();
    });
  }

  ngOnDestroy() {
    this.ro?.disconnect();
    this.chart?.dispose();
  }

  f = fmt;
  date = (d: string) =>
    fmtDate(d, this.spec().grain === 'month' || this.spec().grain === 'quarter' ? 'month' : 'short');

  private async render() {
    if (!this.chart) return;
    const s = this.spec();
    if (s.kind === 'map') await ensureWorldMap();
    // Animate data transitions instead of redrawing from scratch.
    this.chart.setOption(this.option(s), { notMerge: false, replaceMerge: ['series', 'xAxis', 'yAxis', 'visualMap'] });
  }

  private option(s: ChartSpec): echarts.EChartsCoreOption {
    const t = (n: string) => this.theme.token(n);
    const palette = this.theme.series();
    const ink2 = t('--ink-2'),
      ink3 = t('--ink-3'),
      grid = t('--grid'),
      axis = t('--axis'),
      bg = t('--bg-1');
    const fmtv = (v: unknown) => (typeof v === 'number' ? fmt(v, s.format) : '—');
    const base = {
      animationDuration: 700,
      animationEasing: 'cubicOut' as const,
      animationDurationUpdate: 500,
      textStyle: { fontFamily: 'Inter Tight, Inter, system-ui, sans-serif', color: ink2 },
      color: palette,
      tooltip: {
        backgroundColor: t('--bg-2'),
        borderColor: t('--line-2'),
        borderWidth: 1,
        padding: [8, 12],
        textStyle: { color: t('--ink-1'), fontSize: 12.5 },
        extraCssText: 'border-radius:10px;box-shadow:0 12px 32px -12px rgba(0,0,0,.5)',
      },
    };
    const xLabel = (v: string) =>
      s.isTime ? fmtDate(v, s.grain === 'month' || s.grain === 'quarter' || s.grain === 'year' ? 'month' : 'short') : v;
    const legend =
      s.series.length > 1
        ? { top: 0, right: 0, icon: 'roundRect', itemWidth: 10, itemHeight: 10, textStyle: { color: ink2 } }
        : undefined;
    const gridBox = { left: 8, right: 16, top: legend ? 32 : 14, bottom: 8, containLabel: true };
    const valueAxis = {
      type: 'value',
      axisLabel: { color: ink3, formatter: fmtv },
      splitLine: { lineStyle: { color: grid } },
      axisLine: { show: false },
    };

    switch (s.kind) {
      case 'donut':
        return {
          ...base,
          tooltip: { ...base.tooltip, trigger: 'item', valueFormatter: fmtv },
          legend: { bottom: 0, icon: 'circle', textStyle: { color: ink2 } },
          series: [
            {
              type: 'pie',
              radius: ['58%', '78%'],
              center: ['50%', '45%'],
              itemStyle: { borderColor: bg, borderWidth: 2, borderRadius: 4 },
              label: { show: false },
              data: s.categories.map((c, i) => ({ name: c, value: s.series[0].data[i] })),
            },
          ],
        };
      case 'funnel':
        return {
          ...base,
          tooltip: { ...base.tooltip, trigger: 'item', valueFormatter: fmtv },
          series: [
            {
              type: 'funnel',
              left: '5%',
              width: '90%',
              sort: 'descending',
              gap: 2,
              minSize: '8%',
              label: { color: t('--ink-1'), formatter: (p: LabelParams) => `${p.name}  ${fmtv(p.value)}` },
              itemStyle: { borderColor: bg, borderWidth: 0 },
              data: s.categories.map((c, i) => ({
                name: c.replace(/_/g, ' '),
                value: s.series[0].data[i],
                itemStyle: {
                  color:
                    [t('--seq-5'), t('--seq-4'), t('--seq-3'), t('--seq-2'), t('--seq-1'), t('--seq-1')][i] ??
                    t('--seq-1'),
                },
              })),
            },
          ],
        };
      case 'heatmap': {
        const heat = s.heat ?? { x: [], y: [], cells: [] };
        const vals = heat.cells.map((c) => c[2]).filter((v): v is number => v !== null);
        const cellOf = (p: LabelParams) => (Array.isArray(p.value) ? (p.value as number[]) : [0, 0, 0]);
        return {
          ...base,
          tooltip: {
            ...base.tooltip,
            formatter: (p: LabelParams) => {
              const [x, y, v] = cellOf(p);
              return `${heat.y[y]}<br>${xLabel(heat.x[x])}: <b>${fmtv(v)}</b>`;
            },
          },
          grid: { left: 8, right: 16, top: 8, bottom: 48, containLabel: true },
          xAxis: {
            type: 'category',
            data: heat.x.map(xLabel),
            axisLabel: { color: ink3 },
            axisLine: { lineStyle: { color: axis } },
            splitArea: { show: false },
          },
          yAxis: { type: 'category', data: heat.y, axisLabel: { color: ink2 }, axisLine: { show: false } },
          visualMap: {
            min: Math.min(...vals),
            max: Math.max(...vals),
            orient: 'horizontal',
            left: 'center',
            bottom: 0,
            itemHeight: 120,
            calculable: false,
            inRange: { color: [t('--seq-1'), t('--seq-2'), t('--seq-3'), t('--seq-4'), t('--seq-5')] },
            textStyle: { color: ink3 },
            formatter: fmtv,
          },
          series: [
            { type: 'heatmap', data: heat.cells, itemStyle: { borderColor: bg, borderWidth: 2, borderRadius: 3 } },
          ],
        };
      }
      case 'map': {
        const data = s.categories.map((c, i) => ({ name: GEO_ALIAS[c] ?? c, value: s.series[0].data[i] }));
        const vals = data.map((d) => d.value ?? 0);
        return {
          ...base,
          tooltip: {
            ...base.tooltip,
            trigger: 'item',
            formatter: (p: LabelParams) =>
              typeof p.value === 'number' && !Number.isNaN(p.value) ? `${p.name}: <b>${fmtv(p.value)}</b>` : p.name,
          },
          visualMap: {
            min: 0,
            max: Math.max(...vals, 1),
            left: 8,
            bottom: 8,
            itemHeight: 90,
            calculable: false,
            textStyle: { color: ink3 },
            formatter: fmtv,
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
              itemStyle: { areaColor: t('--bg-3'), borderColor: bg, borderWidth: 0.6 },
              data,
            },
          ],
        };
      }
      case 'hbar': {
        const highlight = s.highlight ?? [];
        return {
          ...base,
          legend,
          tooltip: { ...base.tooltip, trigger: 'axis', axisPointer: { type: 'shadow' }, valueFormatter: fmtv },
          grid: { ...gridBox, right: 56 },
          xAxis: { ...valueAxis, splitLine: { lineStyle: { color: grid } } },
          yAxis: {
            type: 'category',
            inverse: true,
            data: s.categories,
            axisLabel: { color: ink2, width: 160, overflow: 'truncate' },
            axisLine: { lineStyle: { color: axis } },
            axisTick: { show: false },
          },
          series: s.series.map((ser, si) => ({
            type: 'bar',
            name: ser.name,
            data: ser.data,
            barMaxWidth: 18,
            barGap: '20%',
            itemStyle: {
              borderRadius: [0, 4, 4, 0],
              color: highlight.length
                ? (p: LabelParams) => (highlight.includes(p.name) ? t('--accent') : palette[si])
                : palette[si],
            },
            label:
              s.series.length === 1
                ? {
                    show: true,
                    position: 'right',
                    color: ink2,
                    formatter: (p: LabelParams) => fmtv(p.value),
                    fontSize: 11.5,
                  }
                : undefined,
          })),
        };
      }
      case 'bar':
        return {
          ...base,
          legend,
          tooltip: { ...base.tooltip, trigger: 'axis', axisPointer: { type: 'shadow' }, valueFormatter: fmtv },
          grid: gridBox,
          xAxis: {
            type: 'category',
            data: s.categories.map(xLabel),
            axisLabel: { color: ink3 },
            axisLine: { lineStyle: { color: axis } },
            axisTick: { show: false },
          },
          yAxis: valueAxis,
          series: s.series.map((ser, si) => ({
            type: 'bar',
            name: ser.name,
            barMaxWidth: 24,
            itemStyle: { borderRadius: [4, 4, 0, 0], color: palette[si] },
            data: ser.data.map((v, i) => ({
              value: v,
              itemStyle: s.partialFrom && s.categories[i] >= s.partialFrom ? { opacity: 0.45 } : undefined,
            })),
          })),
        };
      case 'forecast': {
        const f = s.forecast ?? { categories: [], value: [], lower: [], upper: [] };
        const cats = [...s.categories, ...f.categories];
        const n = s.categories.length;
        const pad = (arr: (number | null)[], before: number) => [...Array(before).fill(null), ...arr];
        const lastActual = s.series[0].data[n - 1];
        return {
          ...base,
          legend: {
            top: 0,
            right: 0,
            textStyle: { color: ink2 },
            data: [s.series[0].name, 'Forecast', '80% interval'],
          },
          tooltip: { ...base.tooltip, trigger: 'axis', valueFormatter: fmtv },
          grid: { ...gridBox, top: 32 },
          xAxis: {
            type: 'category',
            boundaryGap: false,
            data: cats.map(xLabel),
            axisLabel: { color: ink3 },
            axisLine: { lineStyle: { color: axis } },
            axisTick: { show: false },
          },
          yAxis: valueAxis,
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
              itemStyle: { color: t('--ai'), borderColor: bg, borderWidth: 2 },
            },
          ],
        };
      }
      default: {
        const markLine =
          s.target != null
            ? {
                silent: true,
                symbol: 'none',
                lineStyle: { color: ink3, type: 'solid', width: 1 },
                label: { color: ink3, formatter: `Target ${fmtv(s.target)}`, position: 'insideEndTop' },
                data: [{ yAxis: s.target }],
              }
            : undefined;
        // The in-progress period is drawn as a separate faint dashed segment so it never reads as a collapse.
        const partialFrom = s.partialFrom;
        const partialIdx = partialFrom ? s.categories.findIndex((c) => c >= partialFrom) : -1;
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
        const solid = (data: (number | null)[]) =>
          partialIdx > 0 ? data.map((v, i) => (i < partialIdx ? v : null)) : data;
        const partial = (data: (number | null)[]) =>
          data.map((v, i) => (partialIdx > 0 && i >= partialIdx - 1 ? v : null));
        const series: echarts.EChartsCoreOption[] = s.series.map((ser, si) => ({
          type: 'line',
          name: ser.name,
          data: solid(ser.data),
          smooth: 0.25,
          showSymbol: false,
          symbolSize: 8,
          connectNulls: false,
          lineStyle: { width: 2, color: palette[si] },
          itemStyle: { color: palette[si], borderColor: bg, borderWidth: 2 },
          areaStyle: s.kind === 'area' ? { opacity: 0.1, color: palette[si] } : undefined,
          markLine: si === 0 ? markLine : undefined,
          markPoint: si === 0 ? markPoint : undefined,
          endLabel: s.series.length > 1 ? { show: true, color: ink2, formatter: '{a}' } : undefined,
        }));
        if (partialIdx > 0) {
          s.series.forEach((ser, si) =>
            series.push({
              type: 'line',
              name: ser.name,
              data: partial(ser.data),
              smooth: 0.25,
              showSymbol: true,
              symbolSize: 6,
              connectNulls: false,
              lineStyle: { width: 1.5, type: [4, 4], color: palette[si], opacity: 0.55 },
              itemStyle: { color: bg, borderColor: palette[si], borderWidth: 1.5 },
              tooltip: { valueFormatter: (v: unknown) => `${fmtv(v)} (in progress)` },
              markArea:
                si === 0
                  ? {
                      silent: true,
                      itemStyle: { color: t('--bg-3'), opacity: 0.35 },
                      data: [[{ xAxis: Math.max(0, partialIdx - 0.5) }, { xAxis: s.categories.length - 1 }]],
                    }
                  : undefined,
            }),
          );
        }
        return {
          ...base,
          legend: legend ? { ...legend, data: s.series.map((x) => x.name) } : undefined,
          tooltip: {
            ...base.tooltip,
            trigger: 'axis',
            axisPointer: { type: 'line', lineStyle: { color: axis } },
            valueFormatter: fmtv,
          },
          grid: gridBox,
          xAxis: {
            type: 'category',
            boundaryGap: false,
            data: s.categories.map(xLabel),
            axisLabel: { color: ink3, hideOverlap: true },
            axisLine: { lineStyle: { color: axis } },
            axisTick: { show: false },
          },
          yAxis: { ...valueAxis, scale: s.format === 'percent' },
          series,
        };
      }
    }
  }
}
