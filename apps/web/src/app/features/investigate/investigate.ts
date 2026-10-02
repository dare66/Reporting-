import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { RANGES, fmt, fmtChange, fmtDate } from '../../core/format';
import { Chart } from '../../shared/chart';
import { ChartSpec } from '../../shared/chart-spec';
import { Drivers } from '../../shared/drivers';
import { Evidence } from '../../shared/evidence';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';

/** Root-cause workspace: the change, its drivers, when it began, and the evidence. */
@Component({
  selector: 'app-investigate',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Drivers, Chart, Icon, Evidence, Working, ErrorState],
  template: `
  <div class="page">
    <header class="page-head">
      <div>
        <a routerLink="/home" class="muted back">← Command Centre</a>
        <div class="eyebrow signal">Root cause analysis</div>
        <h1>{{ rc()?.label ?? 'Investigating…' }}</h1>
        @if (rc(); as r) {
          <p class="lede">{{ r.label }} moved <b [class.neg]="r.sentiment === 'negative'" [class.pos]="r.sentiment === 'positive'">{{ change() }}</b>
            from {{ f(r.previous.value) }} to {{ f(r.current.value) }} — {{ r.current.period.label }} vs the comparable prior period.</p>
        }
      </div>
      <div class="row wrap">
        <div class="seg">@for (r of ranges; track r.key) { <button [class.on]="range() === r.key" (click)="range.set(r.key); load()">{{ r.label }}</button> }</div>
        @if (auth.can('ai.use')) { <a class="btn ai" routerLink="/ai" [queryParams]="{ q: 'Why did ' + (rc()?.label ?? metric()) + ' change?' }"><app-icon name="ai" [size]="16"/> Ask AI</a> }
      </div>
    </header>

    @if (error()) { <app-error [message]="error()!" (retry)="load()"/> }
    @else if (!rc()) { <app-working title="Investigating the change" [steps]="['Comparing periods', 'Decomposing by dimension', 'Locating the onset', 'Ranking drivers']"/> }
    @else {
      @let r = rc();
      <section class="panel panel-pad tree"><app-drivers [data]="r" (explore)="focus($event)"/></section>

      <section class="grid">
        <div class="panel">
          <div class="panel-head"><div><div class="eyebrow">Daily series</div><h3>When did it start?</h3></div>
            @if (r.onset?.significant) { <span class="badge critical">Shift on {{ date(r.onset.date) }} · {{ r.onset.shift_sigma }}σ</span> }</div>
          <div style="padding:8px 16px 16px"><app-chart [spec]="onsetSpec()" [height]="260"/></div>
        </div>
        <div class="panel">
          <div class="panel-head"><div><div class="eyebrow">Explained share by dimension</div><h3>Where is it concentrated?</h3></div></div>
          <div class="dims">
            @for (d of r.dimensions; track d.key) {
              <button class="dim" [class.on]="dim() === d.key" (click)="dim.set(d.key)">
                <span>{{ d.label }}</span><span class="bar"><i [style.width.%]="d.explained_share * 100"></i></span><b>{{ (d.explained_share * 100).toFixed(0) }}%</b>
              </button>
            }
          </div>
        </div>
      </section>

      @if (dimData(); as d) {
        <section class="panel">
          <div class="panel-head"><div><div class="eyebrow">{{ d.label }}</div><h3>Contribution to the change</h3></div>
            <span class="muted small">Impact = change in {{ r.label }} attributable to each {{ d.label.toLowerCase() }} (leave-one-out)</span></div>
          <div class="scroll-x" style="padding:0 12px 12px">
            <table class="table">
              <thead><tr><th>{{ d.label }}</th><th class="r">Before</th><th class="r">After</th><th class="r">Volume share</th><th class="r">Impact</th><th>Share of change</th></tr></thead>
              <tbody>@for (m of d.members; track m.member) {
                <tr><td><b>{{ m.member }}</b></td><td class="r">{{ f(m.previous_value) }}</td><td class="r">{{ f(m.current_value) }}</td>
                  <td class="r">{{ (m.volume_share * 100).toFixed(0) }}%</td><td class="r">{{ impact(m.impact) }}</td>
                  <td><span class="meter"><i [style.width.%]="Math.min(100, Math.abs(m.impact_share ?? 0) * 100)" [class.neg]="(m.impact_share ?? 0) < 0"></i></span> {{ ((m.impact_share ?? 0) * 100).toFixed(0) }}%</td></tr>
              }</tbody>
            </table>
          </div>
        </section>
      }
      <div class="row wrap actions">
        <button class="btn" (click)="evidence.set(true)"><app-icon name="evidence" [size]="16"/> View evidence ({{ r.evidence.length }} queries)</button>
        @if (auth.can('alerts.manage')) { <a class="btn" routerLink="/ai" [queryParams]="{ q: 'Alert me when ' + r.label + ' moves further' }"><app-icon name="alert" [size]="16"/> Create alert</a> }
        @if (auth.can('reports.manage')) { <a class="btn" routerLink="/ai" [queryParams]="{ q: 'Create a management report' }"><app-icon name="report" [size]="16"/> Management report</a> }
        <span class="muted small">Method: {{ r.method.replaceAll('_', ' ') }}</span>
      </div>
    }
  </div>
  @if (evidence()) { <app-evidence [title]="(rc()?.label ?? '') + ' decomposition'" calculation="impact(m) = V(current) − V(current with m reverted to the prior period)" [items]="rc()!.evidence" (close)="evidence.set(false)"/> }`,
  styles: [`.back{font-size:12.5px;display:block;width:max-content;margin-bottom:10px}.neg{color:var(--neg)}.pos{color:var(--pos)}
    .tree{margin-bottom:20px}.grid{display:grid;grid-template-columns:1.2fr 1fr;gap:20px;margin-bottom:20px}
    .dims{display:grid;padding:10px 12px 14px}.dim{display:grid;grid-template-columns:140px 1fr 44px;gap:12px;align-items:center;padding:10px;border:0;background:none;border-radius:var(--r-md);cursor:pointer;color:inherit;text-align:left}
    .dim:hover,.dim.on{background:var(--bg-3)}.bar{height:6px;border-radius:3px;background:var(--bg-3);overflow:hidden}.dim.on .bar{background:var(--bg-1)}.bar i{display:block;height:100%;background:var(--accent);border-radius:3px}
    .meter{display:inline-block;width:90px;height:5px;border-radius:3px;background:var(--bg-3);vertical-align:middle;margin-right:6px;overflow:hidden}.meter i{display:block;height:100%;background:var(--accent)}.meter i.neg{background:var(--ink-3)}
    .small{font-size:12px}.actions{margin-top:20px}
    @media(max-width:960px){.grid{grid-template-columns:1fr}}`],
})
export class Investigate implements OnInit {
  private api = inject(Api);
  private router = inject(Router);
  readonly auth = inject(Auth);
  readonly metric = input<string>('decisions.sla_compliance');
  readonly ranges = RANGES.filter(r => ['last_7_days', 'last_30_days', 'last_90_days', 'this_month', 'this_quarter'].includes(r.key));
  readonly range = signal('last_30_days');
  readonly rc = signal<any | null>(null);
  readonly error = signal<string | null>(null);
  readonly dim = signal<string | null>(null);
  readonly evidence = signal(false);
  Math = Math;

  readonly change = computed(() => fmtChange(this.rc()?.change, this.rc()?.change_pct, this.rc()?.format));
  readonly dimData = computed(() => this.rc()?.dimensions.find((d: any) => d.key === (this.dim() ?? this.rc()?.dimensions[0]?.key)));
  readonly onsetSpec = computed<ChartSpec>(() => {
    const r = this.rc();
    const pts = r?.onset?.series ?? [];
    return { kind: 'line', isTime: true, format: r?.format ?? 'number', categories: pts.map((p: any) => p.date),
      series: [{ key: 'v', name: r?.label ?? '', format: r?.format, data: pts.map((p: any) => p.value) }],
      markers: r?.onset?.significant ? [{ period: r.onset.date, label: 'Shift began' }] : [] };
  });

  ngOnInit() { this.load(); }

  async load() {
    this.rc.set(null);
    this.error.set(null);
    try { this.rc.set((await this.api.post('/analysis/root-cause', { metric: this.metric(), range: this.range() })).data); }
    catch (e) { this.error.set(errorMessage(e)); }
  }

  focus(d: any) { this.dim.set(d.dimension); }
  f = (v: number | null) => fmt(v, this.rc()?.format);
  impact = (v: number) => (this.rc()?.format === 'percent' ? `${v >= 0 ? '+' : '−'}${Math.abs(v * 100).toFixed(2)} pts` : `${v >= 0 ? '+' : '−'}${fmt(Math.abs(v), this.rc()?.format)}`);
  date = (d: string) => fmtDate(d, 'long');
}
