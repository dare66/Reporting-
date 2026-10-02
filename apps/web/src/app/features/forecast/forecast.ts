import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../core/api.service';
import { fmt } from '../../core/format';
import { Envelope, Forecast, Scenario, ScenarioAssumptions } from '../../core/models';
import { Chart } from '../../shared/chart';
import { specFromForecast } from '../../shared/chart-spec';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';

const METRICS = [
  { ref: 'applications.total_applications', label: 'Applications' },
  { ref: 'revenue.revenue', label: 'Revenue' },
  { ref: 'decisions.sla_compliance', label: 'Processing SLA' },
  { ref: 'decisions.decided_applications', label: 'Decisions (workload)' },
  { ref: 'capacity.processing_capacity', label: 'Processing capacity' },
  { ref: 'decisions.approval_rate', label: 'Approval rate' },
];
const HORIZONS = [
  { key: '7d', label: '7 days' },
  { key: '30d', label: '30 days' },
  { key: '90d', label: '90 days' },
  { key: '6m', label: '6 months' },
  { key: '12m', label: '12 months' },
];

@Component({
  selector: 'app-forecast',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Chart, FormsModule, Icon, Working, ErrorState],
  templateUrl: './forecast.html',
  styleUrl: './forecast.scss',
})
export class ForecastPage implements OnInit {
  private api = inject(Api);
  readonly metrics = METRICS;
  readonly horizons = HORIZONS;
  readonly metric = signal(METRICS[0].ref);
  readonly horizon = signal('6m');
  readonly forecast = signal<Forecast | null>(null);
  readonly error = signal<string | null>(null);
  readonly sliders = [
    {
      key: 'demand_change_pct',
      label: 'Application demand',
      min: -50,
      max: 80,
      hint: 'Change in applications vs. the last 30 days',
    },
    { key: 'officer_change_pct', label: 'Processing officers', min: -40, max: 80, hint: 'Hiring or attrition' },
    { key: 'productivity_change_pct', label: 'Productivity', min: -30, max: 50, hint: 'Automation, process change' },
  ] as const;
  readonly assume = signal<ScenarioAssumptions>({
    demand_change_pct: 20,
    officer_change_pct: 0,
    productivity_change_pct: 0,
  });
  readonly scenario = signal<Scenario | null>(null);
  readonly scenarioError = signal<string | null>(null);
  readonly name = signal('');
  private timer?: ReturnType<typeof setTimeout>;
  Math = Math;
  f = fmt;
  sign = (v: number) => (v > 0 ? '+' + v : String(v));
  belowTarget = (s: Scenario) => s.projected.sla_compliance !== null && s.projected.sla_compliance < s.sla_target;

  readonly spec = computed(() => {
    const f = this.forecast();
    return f ? specFromForecast(f.history, f.points, f.diagnostics.format, f.diagnostics.label, f.grain) : null;
  });

  ngOnInit() {
    this.run();
    this.simulate();
  }
  async run() {
    this.forecast.set(null);
    this.error.set(null);
    try {
      const body = { metric: this.metric(), horizon: this.horizon() };
      this.forecast.set((await this.api.post<Envelope<Forecast>>('/analysis/forecast', body)).data);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
  set(k: keyof ScenarioAssumptions, v: number) {
    this.assume.update((a) => ({ ...a, [k]: +v }));
    clearTimeout(this.timer);
    this.timer = setTimeout(() => this.simulate(), 250);
  }
  reset() {
    this.assume.set({ demand_change_pct: 0, officer_change_pct: 0, productivity_change_pct: 0 });
    this.simulate();
  }
  async simulate(save = false) {
    const body = save ? { ...this.assume(), save_as: this.name() } : this.assume();
    this.scenarioError.set(null);
    try {
      this.scenario.set((await this.api.post<Envelope<Scenario>>('/analysis/scenario', body)).data);
      if (save) this.name.set('');
    } catch (e) {
      this.scenarioError.set(errorMessage(e));
    }
  }
}
