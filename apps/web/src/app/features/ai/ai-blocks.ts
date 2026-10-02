import { ChangeDetectionStrategy, Component, inject, input, output, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { fmt, fmtDate } from '../../core/format';
import { Chart } from '../../shared/chart';
import { specFromBlock, specFromForecast } from '../../shared/chart-spec';
import { Drivers } from '../../shared/drivers';
import { Globe } from '../../shared/globe';
import { Icon } from '../../shared/icon';
import { Kpi } from '../../shared/kpi';

/** Renders one structured block produced by the AI analyst. */
@Component({
  selector: 'app-ai-block',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Kpi, Chart, Drivers, Icon, RouterLink, Globe],
  template: `
    @let b = block();
    @switch (b.type) {
      @case ('kpis') {
        <div class="kpis">@for (c of b.cards; track c.ref) { <app-kpi [card]="c"/> }</div>
      }
      @case ('chart') {
        <div class="card">
          <div class="head"><b>{{ b.title }}</b>@if (b.viz?.reason) { <span class="why" [title]="b.viz.reason">why this chart?</span> }</div>
          @if (b.viz?.type === 'map') {
            <div class="seg mini"><button [class.on]="globe()" (click)="globe.set(true)">3D globe</button><button [class.on]="!globe()" (click)="globe.set(false)">2D map</button></div>
            @if (globe()) { <app-globe [data]="globeData(b)" [height]="300" [format]="b.series[0].format" (pick)="ask.emit('Show ' + b.series[0].label + ' for ' + $event + ' by institution')"/> }
            @else { <app-chart [spec]="spec(b)" [height]="300"/> }
          } @else {
            <app-chart [spec]="spec(b)" [height]="b.rows ? Math.max(180, Math.min(420, b.rows.length * 30 + 40)) : 260" (select)="drill(b, $event.category)"/>
          }
        </div>
      }
      @case ('table') {
        <div class="card"><div class="head"><b>{{ b.title }}</b></div>
          <div class="scroll-x"><table class="table">
            <thead><tr><th>{{ b.dimension.label }}</th>@for (s of b.series; track s.key) { <th class="r">{{ s.label }}</th> }</tr></thead>
            <tbody>@for (r of b.rows; track $index) { <tr><td>{{ r[b.dimension.key] }}</td>@for (s of b.series; track s.key) { <td class="r">{{ f(r[s.key], s.format) }}</td> }</tr> }</tbody>
          </table></div></div>
      }
      @case ('drivers') { <div class="card"><app-drivers [data]="b" (explore)="ask.emit('Show ' + b.label + ' for ' + $event.member + ' over time')"/></div> }
      @case ('forecast') {
        <div class="card"><div class="head"><b>{{ b.title }}</b><span class="badge ai">{{ b.method.replaceAll('_', ' ') }}</span>
          @if (b.diagnostics?.backtest_mape !== null) { <span class="muted small">backtest error {{ (b.diagnostics.backtest_mape * 100).toFixed(1) }}%</span> }</div>
          <app-chart [spec]="forecastSpec(b)" [height]="280"/></div>
      }
      @case ('scenario') {
        <div class="card scenario">
          <div class="head"><b>What-if simulation</b>
            <span class="muted small">demand {{ sign(b.assumptions.demand_change_pct) }}% · officers {{ sign(b.assumptions.officer_change_pct) }}% · productivity {{ sign(b.assumptions.productivity_change_pct) }}%</span></div>
          <div class="cmp">
            @for (row of scenarioRows(b); track row.label) {
              <div class="cmp-row"><span>{{ row.label }}</span><span class="muted">{{ row.base }}</span><span class="arrow">→</span><b [class.neg]="row.bad">{{ row.proj }}</b></div>
            }
          </div>
          @if (b.projected.additional_officers_needed) { <p class="call"><app-icon name="user" [size]="15"/> About <b>{{ b.projected.additional_officers_needed }}</b> more officers to restore {{ f(b.sla_target, 'percent') }} SLA.</p> }
          <p class="muted small">SLA sensitivity fitted by OLS on {{ b.model.observations }} weeks (R² {{ b.model.r_squared?.toFixed(2) }}). <a routerLink="/forecast" class="link">Adjust assumptions →</a></p>
        </div>
      }
      @case ('report') {
        <div class="card artifact">
          <div class="doc"><app-icon name="report" [size]="22"/></div>
          <div class="grow"><div class="eyebrow">Report · v{{ b.version }} · {{ b.status }}</div><b class="t">{{ b.title }}</b>
            <div class="muted small">{{ b.note }}</div>
            <div class="chips">@for (s of b.sections; track s.id) { <span class="chip" [class.err]="s.error">{{ s.title }}</span> }</div>
            <div class="row wrap" style="margin-top:10px">
              <a class="btn sm primary" [routerLink]="['/reports', b.report_id]">Open report</a>
              @for (fmtKey of ['pdf', 'pptx', 'xlsx']; track fmtKey) {
                <button class="btn sm" (click)="exportReport(b.report_id, fmtKey)" [disabled]="exporting() === fmtKey"><app-icon name="download" [size]="14"/>{{ exporting() === fmtKey ? 'Generating…' : fmtKey === 'pptx' ? 'PPT' : fmtKey.toUpperCase() }}</button>
              }
            </div>
            @if (exportError()) { <p class="neg small">{{ exportError() }}</p> }
          </div>
        </div>
      }
      @case ('alert') {
        <div class="card artifact"><div class="doc alert"><app-icon name="alert" [size]="22"/></div>
          <div class="grow kv"><span>Metric</span><b>{{ b.rule.metric }}</b><span>Condition</span><b>{{ b.rule.condition }}</b><span>Window</span><b>{{ b.rule.window }}</b>
            <span>Frequency</span><b>{{ b.rule.frequency }}</b><span>Channels</span><b>{{ b.rule.channels.join(' + ') }}</b></div>
          <a class="btn sm" routerLink="/alerts">Manage</a></div>
      }
      @case ('dashboard') {
        <div class="card artifact"><div class="doc"><app-icon name="dashboard" [size]="22"/></div>
          <div class="grow"><b class="t">{{ b.title }}</b><div class="chips">@for (w of b.widgets; track $index) { <span class="chip">{{ w.title }}</span> }</div></div>
          <a class="btn sm primary" [routerLink]="['/dashboards', b.dashboard_id]">Open</a></div>
      }
      @case ('anomalies') {
        <div class="card"><div class="head"><b>{{ b.title }}</b></div>
          @for (a of b.items; track a.id) {
            <div class="anom"><span class="badge" [class.critical]="a.severity !== 'medium'" [class.warning]="a.severity === 'medium'">! {{ a.severity }}</span>
              <span class="grow"><b>{{ a.evidence.label }}</b> <span class="muted">{{ fmtDate(a.period) }}</span><br>
              <small class="muted">expected {{ f(a.expected, a.evidence.format) }} · actual <b>{{ f(a.actual, a.evidence.format) }}</b> · {{ Math.abs(a.score).toFixed(1) }}σ {{ a.evidence.direction }}</small></span>
              <button class="btn sm" (click)="ask.emit('Why did ' + a.evidence.label + ' change?')">Investigate</button></div>
          } @empty { <p class="muted">No open anomalies.</p> }</div>
      }
      @case ('capabilities') {
        <div class="caps">@for (c of b.items; track c.title) { <button class="cap" (click)="ask.emit(c.example)"><b>{{ c.title }}</b><span>{{ c.example }}</span></button> }</div>
      }
    }`,
  styles: [`
    :host{display:block}
    .card{border:1px solid var(--line);border-radius:var(--r-lg);padding:14px 16px;background:var(--bg-1)}
    .head{display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap}
    .why{font-size:11.5px;color:var(--ink-3);border-bottom:1px dotted var(--ink-3);cursor:help}
    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));border:1px solid var(--line);border-radius:var(--r-lg);background:var(--bg-1)}
    .kpis app-kpi+app-kpi{border-left:1px solid var(--line)}
    .seg.mini{margin-bottom:8px}
    .small{font-size:12px}.neg{color:var(--neg)}.link{color:var(--ai)}
    .cmp{display:grid;gap:2px;margin:6px 0 10px}
    .cmp-row{display:grid;grid-template-columns:1.2fr 1fr 20px 1fr;gap:8px;padding:8px 0;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums}
    .cmp-row b.neg{color:var(--neg)} .arrow{color:var(--ink-3)}
    .call{display:flex;gap:8px;align-items:center;padding:10px 12px;border-radius:var(--r-md);background:var(--accent-soft);margin-bottom:8px}
    .artifact{display:flex;gap:14px;align-items:flex-start}
    .doc{width:44px;height:52px;border-radius:8px;display:grid;place-items:center;background:var(--bg-3);color:var(--accent);flex:none}
    .doc.alert{color:var(--neg)}
    .grow{flex:1;min-width:0}.t{font-size:15px;display:block;margin:2px 0}
    .chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.chips .chip{cursor:default;height:24px;font-size:11.5px}.chip.err{border-color:var(--neg);color:var(--neg)}
    .kv{display:grid;grid-template-columns:90px 1fr;gap:4px 12px;font-size:13px}.kv span{color:var(--ink-3)}
    .anom{display:flex;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid var(--line)}
    .caps{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px}
    .cap{display:grid;gap:4px;text-align:left;padding:14px;border-radius:var(--r-md);border:1px solid var(--line);background:var(--bg-1);cursor:pointer;color:inherit}
    .cap:hover{border-color:var(--ai)} .cap span{color:var(--ink-3);font-size:12.5px}`],
})
export class AiBlock {
  private api = inject(Api);
  private router = inject(Router);
  readonly block = input.required<any>();
  readonly ask = output<string>();
  readonly globe = signal(true);
  readonly exporting = signal<string | null>(null);
  readonly exportError = signal<string | null>(null);
  Math = Math;
  f = fmt; fmtDate = fmtDate;
  spec = specFromBlock;
  sign = (v: number) => (v > 0 ? '+' + v : String(v));
  globeData = (b: any) => b.rows.map((r: any) => ({ name: r[b.dimension.key], value: r[b.series[0].key] }));
  forecastSpec = (b: any) => specFromForecast(b.history, b.points, b.format, b.title.replace(' forecast', ''), b.grain);

  drill(b: any, category: string) {
    if (b.dimension) this.ask.emit(`Show ${b.series[0].label} for ${category} over time`);
  }

  scenarioRows(b: any) {
    const B = b.baseline, P = b.projected;
    return [
      { label: 'Applications', base: fmt(B.applications), proj: fmt(P.applications), bad: false },
      { label: 'Capacity', base: fmt(B.capacity), proj: fmt(P.capacity), bad: false },
      { label: 'Utilisation', base: fmt(B.utilisation, 'percent'), proj: fmt(P.utilisation, 'percent'), bad: P.utilisation > B.utilisation },
      { label: 'Processing SLA', base: fmt(B.sla_compliance, 'percent'), proj: fmt(P.sla_compliance, 'percent'), bad: P.sla_compliance < B.sla_compliance },
      { label: 'Revenue', base: fmt(B.revenue, 'currency'), proj: fmt(P.revenue, 'currency'), bad: P.revenue < B.revenue },
    ];
  }

  async exportReport(id: string, format: string) {
    this.exporting.set(format);
    this.exportError.set(null);
    try {
      const ex = (await this.api.post(`/reports/${id}/exports`, { format })).data;
      let status = ex;
      for (let i = 0; i < 60 && !['ready', 'failed'].includes(status.status); i++) {
        await new Promise(r => setTimeout(r, 1000));
        status = (await this.api.get(`/report-exports/${ex.id}`)).data;
      }
      if (status.status !== 'ready') throw new Error(status.error ?? 'Export failed');
      await this.api.download(`/report-exports/${ex.id}/download`, `report.${format}`);
    } catch (e: any) {
      this.exportError.set(e?.message && !e.status ? e.message : errorMessage(e));
    } finally {
      this.exporting.set(null);
    }
  }
}
