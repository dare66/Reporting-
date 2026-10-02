import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../core/api.service';
import { fmt } from '../../core/format';
import { Chart } from '../../shared/chart';
import { specFromForecast } from '../../shared/chart-spec';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';

const METRICS = [
  { ref: 'applications.total_applications', label: 'Applications' }, { ref: 'revenue.revenue', label: 'Revenue' },
  { ref: 'decisions.sla_compliance', label: 'Processing SLA' }, { ref: 'decisions.decided_applications', label: 'Decisions (workload)' },
  { ref: 'capacity.processing_capacity', label: 'Processing capacity' }, { ref: 'decisions.approval_rate', label: 'Approval rate' },
];
const HORIZONS = [{ key: '7d', label: '7 days' }, { key: '30d', label: '30 days' }, { key: '90d', label: '90 days' }, { key: '6m', label: '6 months' }, { key: '12m', label: '12 months' }];

@Component({
  selector: 'app-forecast',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Chart, FormsModule, Icon, Working, ErrorState],
  template: `
  <div class="page">
    <header class="page-head"><div><div class="eyebrow signal">Forecast &amp; scenarios</div><h1>See what's coming — and what to do about it</h1>
      <p class="lede">Statistical forecasts with honest intervals and backtested accuracy, and a what-if model fitted on your own operating history.</p></div></header>

    <section class="panel fc">
      <div class="panel-head wrap-head">
        <div class="row wrap"><select class="select" [ngModel]="metric()" (ngModelChange)="metric.set($event); run()" aria-label="Metric">@for (m of metrics; track m.ref) { <option [value]="m.ref">{{ m.label }}</option> }</select>
          <div class="seg">@for (h of horizons; track h.key) { <button [class.on]="horizon() === h.key" (click)="horizon.set(h.key); run()">{{ h.label }}</button> }</div></div>
        @if (forecast(); as f) { <div class="diag"><span class="badge ai">{{ f.method.replaceAll('_', ' ') }}</span>
          <span>{{ f.diagnostics.observations }} periods</span>@if (f.diagnostics.backtest_mape !== null) { <span>backtest error <b>{{ (f.diagnostics.backtest_mape * 100).toFixed(1) }}%</b></span> }<span>80% interval</span></div> }
      </div>
      @if (error()) { <app-error [message]="error()!" (retry)="run()"/> }
      @else if (!forecast()) { <app-working title="Forecasting" [steps]="['Collecting complete periods', 'Fitting exponential smoothing', 'Backtesting', 'Projecting intervals']"/> }
      @else {
        <div style="padding:8px 16px 16px"><app-chart [spec]="spec()" [height]="340"/></div>
        @if ((forecast().diagnostics.backtest_mape ?? 0) > 0.15) { <p class="warn"><app-icon name="warning" [size]="14"/> Recent backtest error is high — read the band, not the line.</p> }
      }
    </section>

    <section class="panel sim">
      <div class="panel-head"><div><div class="eyebrow">What-if simulation</div><h2>Demand vs. processing capacity</h2></div></div>
      <div class="sim-grid">
        <div class="sliders">
          @for (s of sliders; track s.key) {
            <label class="slider"><span class="row"><b>{{ s.label }}</b><span class="spacer"></span><span class="val">{{ sign(assume()[s.key]) }}%</span></span>
              <input type="range" [min]="s.min" [max]="s.max" step="1" [ngModel]="assume()[s.key]" (ngModelChange)="set(s.key, $event)" [attr.aria-label]="s.label"><small class="muted">{{ s.hint }}</small></label>
          }
          <div class="row"><button class="btn ghost" (click)="reset()">Reset</button><span class="spacer"></span>
            <input class="input" style="max-width:200px" [ngModel]="name()" (ngModelChange)="name.set($event)" placeholder="Scenario name"><button class="btn" (click)="simulate(true)" [disabled]="!name()">Save</button></div>
        </div>
        @if (scenario(); as s) {
          <div class="outcome">
            <div class="big-row">
              <div><div class="eyebrow">Projected SLA</div><div class="hero-figure" [class.neg]="s.projected.sla_compliance < s.sla_target">{{ f(s.projected.sla_compliance, 'percent') }}</div>
                <div class="muted">from {{ f(s.baseline.sla_compliance, 'percent') }} today · target {{ f(s.sla_target, 'percent') }}</div></div>
              <div><div class="eyebrow">Utilisation</div><div class="mid">{{ f(s.projected.utilisation, 'percent') }}</div><div class="meter"><i [style.width.%]="Math.min(100, s.projected.utilisation * 60)" [class.over]="s.projected.utilisation > 1"></i></div></div>
            </div>
            <table class="table"><thead><tr><th></th><th class="r">Today (30d)</th><th class="r">Scenario</th></tr></thead><tbody>
              <tr><td>Applications</td><td class="r">{{ f(s.baseline.applications) }}</td><td class="r">{{ f(s.projected.applications) }}</td></tr>
              <tr><td>Capacity</td><td class="r">{{ f(s.baseline.capacity) }}</td><td class="r">{{ f(s.projected.capacity) }}</td></tr>
              <tr><td>Officers</td><td class="r">{{ s.baseline.officers }}</td><td class="r">{{ s.projected.officers }}</td></tr>
              <tr><td>Revenue</td><td class="r">{{ f(s.baseline.revenue, 'currency') }}</td><td class="r">{{ f(s.projected.revenue, 'currency') }}</td></tr></tbody></table>
            @if (s.projected.additional_officers_needed !== null) { <p class="call"><app-icon name="user" [size]="16"/> Staff requirement to hold {{ f(s.sla_target, 'percent') }} SLA: <b>{{ s.projected.required_officers_for_target }}</b> officers ({{ s.projected.additional_officers_needed > 0 ? '+' + s.projected.additional_officers_needed : 'no change' }}).</p> }
            @for (c of s.caveats; track c) { <p class="warn"><app-icon name="info" [size]="14"/> {{ c }}</p> }
            <p class="muted small">Model: weekly SLA regressed on utilisation two weeks earlier · {{ s.model.observations }} weeks · R² {{ s.model.r_squared?.toFixed(2) }} · slope {{ s.model.slope }}</p>
          </div>
        }
      </div>
    </section>
  </div>`,
  styles: [`.fc,.sim{margin-bottom:20px}.wrap-head{flex-wrap:wrap}.diag{display:flex;gap:12px;align-items:center;font-size:12.5px;color:var(--ink-2);flex-wrap:wrap}
    .warn{display:flex;gap:8px;align-items:center;font-size:12.5px;color:var(--ink-2);padding:0 20px 16px}
    .sim-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr);gap:32px;padding:16px 22px 22px}
    .sliders{display:grid;gap:22px;align-content:start}.slider{display:grid;gap:6px}.slider input{width:100%;accent-color:var(--accent)}.val{font-variant-numeric:tabular-nums;font-weight:600}
    .outcome{display:grid;gap:14px}.big-row{display:grid;grid-template-columns:1.3fr 1fr;gap:20px}.hero-figure.neg{color:var(--neg)}.mid{font-size:32px;font-weight:600;letter-spacing:-.03em}
    .meter{height:6px;border-radius:3px;background:var(--bg-3);overflow:hidden;margin-top:8px}.meter i{display:block;height:100%;background:var(--series-1)}.meter i.over{background:var(--neg)}
    .call{display:flex;gap:8px;align-items:center;padding:12px 14px;border-radius:var(--r-md);background:var(--accent-soft)}.small{font-size:12px}
    @media(max-width:960px){.sim-grid{grid-template-columns:1fr}}`],
})
export class ForecastPage implements OnInit {
  private api = inject(Api);
  readonly metrics = METRICS;
  readonly horizons = HORIZONS;
  readonly metric = signal(METRICS[0].ref);
  readonly horizon = signal('6m');
  readonly forecast = signal<any | null>(null);
  readonly error = signal<string | null>(null);
  readonly sliders = [
    { key: 'demand_change_pct', label: 'Application demand', min: -50, max: 80, hint: 'Change in applications vs. the last 30 days' },
    { key: 'officer_change_pct', label: 'Processing officers', min: -40, max: 80, hint: 'Hiring or attrition' },
    { key: 'productivity_change_pct', label: 'Productivity', min: -30, max: 50, hint: 'Automation, process change' },
  ] as const;
  readonly assume = signal<Record<string, number>>({ demand_change_pct: 20, officer_change_pct: 0, productivity_change_pct: 0 });
  readonly scenario = signal<any | null>(null);
  readonly name = signal('');
  private timer: any;
  Math = Math; f = fmt;
  sign = (v: number) => (v > 0 ? '+' + v : String(v));

  readonly spec = computed(() => {
    const f = this.forecast();
    return specFromForecast(f.history, f.points, f.diagnostics.format, f.diagnostics.label, f.grain);
  });

  ngOnInit() { this.run(); this.simulate(); }
  async run() {
    this.forecast.set(null); this.error.set(null);
    try { this.forecast.set((await this.api.post('/analysis/forecast', { metric: this.metric(), horizon: this.horizon() })).data); }
    catch (e) { this.error.set(errorMessage(e)); }
  }
  set(k: string, v: number) { this.assume.update(a => ({ ...a, [k]: +v })); clearTimeout(this.timer); this.timer = setTimeout(() => this.simulate(), 250); }
  reset() { this.assume.set({ demand_change_pct: 0, officer_change_pct: 0, productivity_change_pct: 0 }); this.simulate(); }
  async simulate(save = false) {
    const body: any = { ...this.assume() };
    if (save) body.save_as = this.name();
    this.scenario.set((await this.api.post('/analysis/scenario', body)).data);
    if (save) this.name.set('');
  }
}
