import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { RANGES, fmt } from '../../core/format';
import { Chart } from '../../shared/chart';
import { ChartSpec, specFromQuery } from '../../shared/chart-spec';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';

const VIZ = [
  { key: 'auto', label: 'Recommended' }, { key: 'line', label: 'Line' }, { key: 'area', label: 'Area' }, { key: 'bar', label: 'Bar' },
  { key: 'donut', label: 'Donut' }, { key: 'funnel', label: 'Funnel' }, { key: 'heatmap', label: 'Heatmap' }, { key: 'map', label: 'Map' },
];

@Component({
  selector: 'app-explore',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Chart, Icon, Working, ErrorState],
  templateUrl: './explore.html',
  styleUrl: './explore.scss',
})
export class Explore implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly metricParam = input<string | undefined>(undefined, { alias: 'metric' });
  readonly dimensionParam = input<string | undefined>(undefined, { alias: 'dimension' });
  readonly countryParam = input<string | undefined>(undefined, { alias: 'country' });

  readonly ranges = RANGES;
  readonly vizTypes = VIZ;
  readonly catalog = signal<any[]>([]);
  readonly modelKey = signal('applications');
  readonly metrics = signal<string[]>(['total_applications']);
  readonly dimension = signal<string | null>(null);
  readonly grain = signal<string | null>('month');
  readonly range = signal('last_12_months');
  readonly filters = signal<{ dimension: string; op: string; value: string[] }[]>([]);
  readonly viz = signal('auto');
  readonly result = signal<any | null>(null);
  readonly error = signal<string | null>(null);
  readonly loading = signal(false);
  readonly sql = signal<string | null>(null);
  readonly members = signal<Record<string, string[]>>({});
  readonly filterDim = signal<string>('');
  readonly drillTrail = signal<{ dimension: string; value: string }[]>([]);
  readonly dashboards = signal<any[]>([]);
  readonly saving = signal(false);
  readonly saved = signal<string | null>(null);

  readonly model = computed(() => this.catalog().find(m => m.key === this.modelKey()));
  readonly dims = computed(() => (this.model()?.dimensions ?? []).filter((d: any) => d.type !== 'time' && d.accessible && !d.key.endsWith('_code')));
  readonly recommended = computed(() => {
    if (this.grain() && this.dimension()) return 'heatmap';
    if (this.grain()) return this.metricFormat() === 'percent' ? 'line' : 'area';
    if (this.dimension() === 'country') return 'map';
    if (this.dimension() === 'stage') return 'funnel';
    return 'bar';
  });
  readonly metricFormat = computed(() => this.model()?.metrics.find((m: any) => m.key === this.metrics()[0])?.format ?? 'number');
  readonly spec = computed<ChartSpec | null>(() => {
    const r = this.result();
    if (!r) return null;
    const type = this.viz() === 'auto' ? this.recommended() : this.viz();
    return specFromQuery(r, { type, orientation: r.rows.length > 6 ? 'horizontal' : undefined });
  });
  readonly reason = computed(() => ({
    heatmap: 'Time × category: a heatmap shows where and when values change.', line: 'Rates over time read best as a line.',
    area: 'Volumes over time: area emphasises magnitude.', map: 'Geographic distribution — a map with a ranked bar alternative.',
    funnel: 'Movement between stages is a funnel.', bar: 'Comparing categories: sorted bars compare magnitudes accurately.',
  } as any)[this.recommended()]);

  async ngOnInit() {
    this.catalog.set((await this.api.get('/semantic-catalog')).data);
    const p = this.metricParam();
    if (p?.includes('.')) {
      const [m, k] = p.split('.');
      this.modelKey.set(m);
      this.metrics.set([k]);
    }
    if (this.dimensionParam()) { this.dimension.set(this.dimensionParam()!); this.grain.set(null); }
    if (this.countryParam()) this.filters.set([{ dimension: 'country', op: 'in', value: [this.countryParam()!] }]);
    if (this.auth.can('dashboards.manage')) this.api.get('/dashboards').then(r => this.dashboards.set(r.data.filter((d: any) => d.can_edit)));
    this.run();
  }

  setModel(key: string) {
    this.modelKey.set(key);
    const m = this.model();
    this.metrics.set([m.metrics.find((x: any) => x.is_kpi)?.key ?? m.metrics[0].key]);
    this.dimension.set(null);
    this.filters.set([]);
    this.drillTrail.set([]);
    this.run();
  }

  toggleMetric(key: string) {
    const cur = this.metrics();
    this.metrics.set(cur.includes(key) ? (cur.length > 1 ? cur.filter(k => k !== key) : cur) : [...cur, key].slice(-3));
    this.run();
  }
  setDim(d: string | null) { this.dimension.set(d); this.drillTrail.set([]); this.run(); }
  setGrain(g: string | null) { this.grain.set(g); this.run(); }
  setRange(r: string) { this.range.set(r); this.run(); }

  async loadMembers(dim: string) {
    if (!dim || this.members()[dim]) return;
    const r = await this.api.post('/query', { model: this.modelKey(), metrics: [this.metrics()[0]], dimensions: [dim], time: { range: 'last_24_months' }, limit: 300 });
    this.members.update(m => ({ ...m, [dim]: r.rows.map((x: any) => String(x[dim])) }));
  }
  addFilter(dim: string, value: string) {
    if (!dim || !value) return;
    this.filters.update(fs => {
      const existing = fs.find(f => f.dimension === dim);
      return existing ? fs.map(f => (f === existing ? { ...f, value: [...new Set([...f.value, value])] } : f)) : [...fs, { dimension: dim, op: 'in', value: [value] }];
    });
    this.run();
  }
  removeFilter(i: number) { this.filters.update(fs => fs.filter((_, j) => j !== i)); this.run(); }

  async run() {
    if (!this.metrics().length) return;
    this.loading.set(true);
    this.error.set(null);
    this.sql.set(null);
    try {
      this.result.set(await this.api.post('/query', this.query()));
    } catch (e) { this.error.set(errorMessage(e)); } finally { this.loading.set(false); }
  }

  query() {
    const metric = this.metrics()[0];
    return {
      model: this.modelKey(), metrics: this.metrics(), dimensions: this.dimension() ? [this.dimension()] : [], filters: this.filters(),
      time: { range: this.range(), ...(this.grain() ? { grain: this.grain() } : {}) },
      sort: this.dimension() && !this.grain() ? [{ key: metric, dir: 'desc' }] : [], limit: 500,
    };
  }

  /** Drill-down: clicking a category narrows to it and descends the hierarchy. */
  async drill(category: string) {
    const dim = this.dimension();
    if (!dim || this.grain()) return;
    try {
      const res = await this.api.post('/analysis/drill', { metric: `${this.modelKey()}.${this.metrics()[0]}`, dimension: dim, value: category, range: this.range(), filters: this.filters() });
      this.drillTrail.update(t => [...t, { dimension: dim, value: category }]);
      this.filters.set(res.filters);
      this.dimension.set(res.level);
      this.result.set(res.result);
    } catch (e) {
      // No lower level: show the member over time instead (drill-through).
      this.filters.update(fs => [...fs.filter(f => f.dimension !== dim), { dimension: dim, op: 'in', value: [category] }]);
      this.dimension.set(null);
      this.grain.set('month');
      this.run();
    }
  }

  async showSql() {
    const r = await this.api.post('/query/explain', this.query());
    this.sql.set(r.sql + '\n\n-- bindings: ' + JSON.stringify(r.bindings));
  }

  async saveTo(dashboardId: string) {
    if (!dashboardId) return;
    this.saving.set(true);
    const m = this.model().metrics.find((x: any) => x.key === this.metrics()[0]);
    await this.api.post(`/dashboards/${dashboardId}/widgets`, {
      type: 'chart', title: `${m.label}${this.dimension() ? ' by ' + this.dimLabel(this.dimension()!) : ''}`,
      query: this.query(), viz: { type: this.viz() === 'auto' ? this.recommended() : this.viz() },
      position: { x: 0, y: 99, w: 6, h: 4 },
    });
    this.saving.set(false);
    this.saved.set(dashboardId);
  }

  dimLabel(k: string) { return this.dims().find((d: any) => d.key === k)?.label ?? k; }
  f = fmt;
}
