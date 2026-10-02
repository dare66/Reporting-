import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { fmt, fmtDate } from '../../core/format';
import { Chart } from '../../shared/chart';
import { specFromForecast, specFromQuery } from '../../shared/chart-spec';
import { Globe } from '../../shared/globe';
import { Icon } from '../../shared/icon';
import { Kpi } from '../../shared/kpi';

/** One dashboard widget: fetches its own governed data and renders by type. */
@Component({
  selector: 'app-widget',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Kpi, Chart, Globe, Icon, RouterLink],
  template: `
    @let w = widget();
    @if (w.type !== 'kpi') { <div class="w-head"><b>{{ w.title }}</b><span class="spacer"></span>
      @if (w.type === 'globe') { <div class="seg mini"><button [class.on]="three()" (click)="three.set(true)">3D</button><button [class.on]="!three()" (click)="three.set(false)">2D</button></div> }</div> }
    @if (error()) { <div class="w-err"><app-icon name="warning" [size]="16"/><span>{{ error() }}</span><button class="btn sm ghost" (click)="load()">Retry</button></div> }
    @else if (!data()) { <div class="skeleton fill"></div> }
    @else {
      @switch (w.type) {
        @case ('kpi') { @for (c of data().data; track c.ref) { <app-kpi [card]="c"/> } }
        @case ('insight') {
          <ol class="ins">@for (i of data().data.slice(0, 4); track i.id) { <li><b>{{ i.title }}</b>@if (i.metric_key) { <a routerLink="/investigate" [queryParams]="{ metric: i.metric_key }">Investigate →</a> }</li> }</ol>
        }
        @case ('table') {
          <div class="tbl"><table class="table"><thead><tr>@for (c of data().data.columns; track c.key) { <th [class.r]="c.role === 'metric'">{{ c.label }}</th> }</tr></thead>
            <tbody>@for (r of data().data.rows; track $index) { <tr>@for (c of data().data.columns; track c.key) { <td [class.r]="c.role === 'metric'">{{ c.role === 'metric' ? f(r[c.key], c.format) : r[c.key] }}</td> }</tr> }</tbody></table></div>
        }
        @case ('globe') {
          @if (three()) { <app-globe [data]="globe()" [height]="height() - 50" [autoRotate]="true"/> }
          @else { <app-chart [spec]="mapSpec()" [height]="height() - 50" [tableToggle]="false"/> }
        }
        @case ('anomalies') {
          <ul class="anom">@for (a of data().data.slice(0, 5); track a.id) {
            <li><span class="badge critical">{{ Math.abs(a.score).toFixed(1) }}σ</span><span>{{ a.evidence.label }} · {{ date(a.period) }}</span><b>{{ f(a.actual, a.evidence.format) }}</b></li>
          } @empty { <li class="muted">No open anomalies</li> }</ul>
        }
        @case ('forecast') { <app-chart [spec]="data().spec" [height]="height() - 50" [tableToggle]="false"/> }
        @case ('text') { <p class="secondary">{{ w.viz?.text }}</p> }
        @default { <app-chart [spec]="chartSpec()" [height]="height() - 50"/> }
      }
    }`,
  styles: [`:host{display:flex;flex-direction:column;height:100%;min-height:0}
    :host ::ng-deep app-kpi .kpi{padding:12px 16px;gap:2px} :host ::ng-deep app-kpi .value{font-size:26px} :host ::ng-deep app-kpi app-spark{margin-top:2px}
    .w-head{display:flex;align-items:center;gap:8px;padding:12px 16px 4px;min-height:40px}.w-head b{font-size:13.5px;font-weight:600}
    .seg.mini button{height:22px;font-size:11px}
    .fill{flex:1;margin:12px 16px 16px}
    :host ::ng-deep app-chart{padding:4px 12px 8px}
    .w-err{display:flex;gap:8px;align-items:center;padding:16px;color:var(--neg);font-size:12.5px}
    .ins{margin:0;padding:6px 16px 12px 34px;display:grid;gap:10px;font-size:13px}.ins a{display:block;color:var(--ai);font-size:12px;margin-top:2px}
    .tbl{overflow:auto;padding:0 8px 8px;flex:1}
    .anom{list-style:none;margin:0;padding:4px 16px 12px;display:grid;gap:8px;font-size:12.5px}.anom li{display:flex;gap:10px;align-items:center}.anom span:nth-child(2){flex:1}`],
})
export class Widget implements OnInit {
  private api = inject(Api);
  readonly widget = input.required<any>();
  readonly dashboardId = input.required<string>();
  readonly height = input(300);
  readonly filters = input<any[]>([]);
  readonly data = signal<any | null>(null);
  readonly error = signal<string | null>(null);
  readonly three = signal(true);
  Math = Math;
  f = fmt; date = (d: string) => fmtDate(d);

  readonly chartSpec = computed(() => specFromQuery(this.data()?.data, this.widget().viz));
  readonly globe = computed(() => (this.data()?.data.rows ?? []).map((r: any) => ({ name: r.country, value: r[this.widget().query.metrics[0]] })));
  readonly mapSpec = computed(() => specFromQuery(this.data()?.data, { type: 'map' }));

  ngOnInit() { this.load(); }

  async load() {
    this.error.set(null);
    const w = this.widget();
    try {
      if (w.type === 'anomalies') {
        const res = await this.api.get('/anomalies', { status: 'open' });
        const own = res.data.filter((a: any) => !w.query.model || a.metric_key.startsWith(w.query.model + '.'));
        this.data.set({ data: own.length ? own : res.data });
      } else if (w.type === 'forecast') {
        const f = (await this.api.post('/analysis/forecast', { metric: `${w.query.model}.${w.query.metrics[0]}`, horizon: w.viz?.horizon ?? '6m' })).data;
        this.data.set({ spec: specFromForecast(f.history.slice(-12), f.points, f.diagnostics.format, f.diagnostics.label, f.grain) });
      } else {
        this.data.set(await this.api.post(`/dashboards/${this.dashboardId()}/widgets/${w.id}/data`, { filters: this.filters() }));
      }
    } catch (e) { this.error.set(errorMessage(e)); }
  }
}
