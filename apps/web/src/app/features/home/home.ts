import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { RANGES, ago, fmt, fmtDate } from '../../core/format';
import { Chart } from '../../shared/chart';
import { ChartSpec } from '../../shared/chart-spec';
import { Evidence } from '../../shared/evidence';
import { Globe } from '../../shared/globe';
import { Icon } from '../../shared/icon';
import { Kpi } from '../../shared/kpi';
import { ErrorState, Working } from '../../shared/states';

const CACHE_KEY = 'aixbi.home';

@Component({
  selector: 'app-home',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Kpi, Icon, Globe, Chart, Evidence, Working, ErrorState],
  templateUrl: './home.html',
  styleUrl: './home.scss',
})
export class Home implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  private router = inject(Router);

  readonly ranges = RANGES.filter(r => ['last_7_days', 'last_30_days', 'this_month', 'this_quarter', 'year_to_date'].includes(r.key));
  readonly range = signal('last_30_days');
  readonly data = signal<any | null>(null);
  readonly error = signal<string | null>(null);
  readonly stale = signal<string | null>(null);
  readonly markets = signal<{ name: string; value: number }[]>([]);
  readonly view3d = signal(true);
  readonly evidence = signal<any | null>(null);

  readonly issues = computed(() => (this.data()?.attention ?? []).filter((a: any) => a.severity !== 'positive'));
  readonly wins = computed(() => (this.data()?.attention ?? []).filter((a: any) => a.severity === 'positive'));
  readonly marketSpec = computed<ChartSpec>(() => ({
    kind: 'hbar', format: 'number', categories: this.markets().slice(0, 10).map(m => m.name),
    series: [{ key: 'v', name: 'Applications', format: 'number', data: this.markets().slice(0, 10).map(m => m.value) }],
  }));
  readonly today = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
  ago = ago; fmt = fmt; fmtDate = fmtDate;

  ngOnInit() {
    // Offline-friendly: show the last good snapshot immediately, clearly marked as cached.
    try {
      const cached = JSON.parse(localStorage.getItem(CACHE_KEY) ?? 'null');
      if (cached?.data) { this.data.set(cached.data); this.stale.set(cached.at); }
    } catch { /* no cache */ }
    this.load();
  }

  async load() {
    this.error.set(null);
    try {
      const [home, markets] = await Promise.all([
        this.api.get('/home', { range: this.range() }),
        this.auth.can('query.run')
          ? this.api.post('/query', { model: 'applications', metrics: ['total_applications'], dimensions: ['country'], time: { range: 'last_12_months' }, sort: [{ key: 'total_applications', dir: 'desc' }], limit: 40 })
          : Promise.resolve(null),
      ]);
      this.data.set(home.data);
      this.stale.set(null);
      if (markets) this.markets.set(markets.rows.map((r: any) => ({ name: r.country, value: r.total_applications })));
      try { localStorage.setItem(CACHE_KEY, JSON.stringify({ at: new Date().toISOString(), data: home.data })); } catch { /* quota */ }
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  router_go(link: string) { this.router.navigateByUrl(link); }

  setRange(r: string) { this.range.set(r); this.load(); }
  openEvidence(i: any) {
    const ev = i.evidence ?? {};
    const items = [ev.queries?.current, ev.queries?.previous, ev.applications?.current, ev.capacity?.current, ev.query, ...(ev.queries && Array.isArray(ev.queries) ? ev.queries : [])].filter(Boolean);
    this.evidence.set({ title: i.title, body: i.body, calculation: ev.calculation ?? ev.method?.replace(/_/g, ' ') ?? null, items });
  }
  investigate(i: any) { this.router.navigate(['/investigate'], { queryParams: { metric: i.metric_key } }); }
  drill(country: string) { this.router.navigate(['/explore'], { queryParams: { metric: 'applications.total_applications', dimension: 'institution', country } }); }
}
