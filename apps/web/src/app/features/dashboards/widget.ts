import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { cell, fmt, fmtDate } from '../../core/format';
import {
  Anomaly,
  Envelope,
  Forecast,
  Insight,
  JsonObject,
  KpiCard,
  QueryFilter,
  QueryResult,
  Widget,
} from '../../core/models';
import { Chart } from '../../shared/chart';
import { ChartSpec, specFromForecast, specFromQuery } from '../../shared/chart-spec';
import { Globe } from '../../shared/globe';
import { Icon } from '../../shared/icon';
import { Kpi } from '../../shared/kpi';

/** What a widget renders, by source. The API's widget-data endpoint returns the first four. */
type WidgetData =
  | { kind: 'kpi'; data: KpiCard[] }
  | { kind: 'insights'; data: Insight[] }
  | { kind: 'static'; data: JsonObject }
  | { kind: 'query'; data: QueryResult }
  | { kind: 'anomalies'; data: Anomaly[] }
  | { kind: 'forecast'; spec: ChartSpec };

/** One dashboard widget: fetches its own governed data and renders by type. */
@Component({
  selector: 'app-widget',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Kpi, Chart, Globe, Icon, RouterLink],
  templateUrl: './widget.html',
  styleUrl: './widget.scss',
})
export class DashboardWidget implements OnInit {
  private api = inject(Api);
  readonly widget = input.required<Widget>();
  readonly dashboardId = input.required<string>();
  readonly height = input(300);
  readonly filters = input<QueryFilter[]>([]);
  readonly data = signal<WidgetData | null>(null);
  readonly error = signal<string | null>(null);
  readonly three = signal(true);
  Math = Math;
  f = fmt;
  cell = cell;
  date = (d: string) => fmtDate(d);

  readonly kpis = computed(() => {
    const d = this.data();
    return d?.kind === 'kpi' ? d.data : null;
  });
  readonly insights = computed(() => {
    const d = this.data();
    return d?.kind === 'insights' ? d.data : null;
  });
  readonly anomalies = computed(() => {
    const d = this.data();
    return d?.kind === 'anomalies' ? d.data : null;
  });
  readonly result = computed(() => {
    const d = this.data();
    return d?.kind === 'query' ? d.data : null;
  });
  readonly forecastSpec = computed(() => {
    const d = this.data();
    return d?.kind === 'forecast' ? d.spec : null;
  });
  readonly chartSpec = computed(() => {
    const r = this.result();
    return r ? specFromQuery(r, this.widget().viz) : null;
  });
  readonly mapSpec = computed(() => {
    const r = this.result();
    return r ? specFromQuery(r, { type: 'map' }) : null;
  });
  readonly globe = computed(() => {
    const key = this.widget().query.metrics?.[0] ?? '';
    return (this.result()?.rows ?? []).map((r) => ({ name: String(r['country']), value: Number(r[key]) }));
  });

  ngOnInit() {
    this.load();
  }

  async load() {
    this.error.set(null);
    const w = this.widget();
    const { model, metrics = [] } = w.query;
    try {
      if (w.type === 'anomalies') {
        const res = await this.api.get<Envelope<Anomaly[]>>('/anomalies', { status: 'open' });
        const own = res.data.filter((a) => !model || a.metric_key.startsWith(model + '.'));
        this.data.set({ kind: 'anomalies', data: own.length ? own : res.data });
      } else if (w.type === 'forecast') {
        const body = { metric: `${model}.${metrics[0]}`, horizon: w.viz['horizon'] ?? '6m' };
        const f = (await this.api.post<Envelope<Forecast>>('/analysis/forecast', body)).data;
        const history = f.history.slice(-12);
        const spec = specFromForecast(history, f.points, f.diagnostics.format, f.diagnostics.label, f.grain);
        this.data.set({ kind: 'forecast', spec });
      } else {
        const url = `/dashboards/${this.dashboardId()}/widgets/${w.id}/data`;
        this.data.set(await this.api.post<WidgetData>(url, { filters: this.filters() }));
      }
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
}
