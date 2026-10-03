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
import * as echarts from 'echarts/core';
import {
  BarChart,
  FunnelChart,
  GaugeChart,
  HeatmapChart,
  LineChart,
  MapChart,
  PieChart,
  ScatterChart,
  TreemapChart,
} from 'echarts/charts';
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
import { fmtDate, fmtWith } from '../core/format';
import { buildOption } from './chart-options';
import { ChartSpec } from './chart-spec';
import { Icon } from './icon';

echarts.use([
  LineChart,
  BarChart,
  PieChart,
  FunnelChart,
  HeatmapChart,
  MapChart,
  ScatterChart,
  TreemapChart,
  GaugeChart,
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
  private kind?: ChartSpec['kind'];

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

  /** Table view values use the widget's number format too. */
  f = (v: number | null | undefined, format?: string) => fmtWith(v, format, this.spec().style?.number);
  date = (d: string) =>
    fmtDate(d, this.spec().grain === 'month' || this.spec().grain === 'quarter' ? 'month' : 'short');

  private async render() {
    if (!this.chart) return;
    const s = this.spec();
    if (s.kind === 'map') await ensureWorldMap();
    const option = buildOption(s, { token: (n) => this.theme.token(n), palette: this.theme.series() });
    // Same kind: animate the data transition. New kind: start clean so no axis or legend lingers.
    const sameKind = this.kind === s.kind;
    this.kind = s.kind;
    this.chart.setOption(
      option,
      sameKind ? { replaceMerge: ['series', 'xAxis', 'yAxis', 'visualMap', 'legend'] } : { notMerge: true },
    );
  }
}
