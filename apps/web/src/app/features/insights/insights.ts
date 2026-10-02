import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ago, fmt, fmtDate } from '../../core/format';
import { Evidence } from '../../shared/evidence';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';

@Component({
  selector: 'app-insights',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, Evidence, Working, ErrorState, RouterLink],
  template: `
  <div class="page">
    <header class="page-head">
      <div><div class="eyebrow ai">AI insights</div><h1>What deserves attention</h1>
        <p class="lede">Insights are computed from governed metrics — period changes, concentrated drivers, demand vs. capacity and statistical anomalies. Every one links to its evidence.</p></div>
      <div class="row">
        @if (auth.can('analytics.advanced')) { <button class="btn" (click)="scan()" [disabled]="busy()"><app-icon name="pulse" [size]="16"/> {{ busy() === 'scan' ? 'Scanning…' : 'Scan for anomalies' }}</button> }
        <button class="btn signal" (click)="generate()" [disabled]="busy()"><app-icon name="refresh" [size]="16"/> {{ busy() === 'gen' ? 'Analysing…' : 'Refresh insights' }}</button>
      </div>
    </header>
    @if (error()) { <app-error [message]="error()!" (retry)="load()"/> }
    @if (busy() === 'gen') { <app-working title="Generating insights" [steps]="['Comparing periods', 'Decomposing changes', 'Checking capacity', 'Ranking']"/> }
    <section class="cols">
      <div class="stack">
        <div class="eyebrow">Insights · {{ insights().length }}</div>
        @for (i of insights(); track i.id) {
          <article class="ins panel rise" [class]="i.severity">
            <div class="row"><span class="badge" [class]="i.severity">{{ i.kind }}</span><span class="muted small">{{ ago(i.created_at) }}</span></div>
            <h3>{{ i.title }}</h3><p class="secondary">{{ i.body }}</p>
            <div class="row"><button class="btn sm" (click)="show(i)"><app-icon name="evidence" [size]="14"/> View evidence</button>
              @if (i.metric_key) { <a class="btn sm ghost" routerLink="/investigate" [queryParams]="{ metric: i.metric_key }">Investigate →</a> }</div>
          </article>
        } @empty { <p class="muted">No insights yet. Refresh to analyse the latest data.</p> }
      </div>
      <div class="stack">
        <div class="eyebrow">Open anomalies · {{ anomalies().length }}</div>
        @for (a of anomalies(); track a.id) {
          <article class="anom panel">
            <div class="row"><span class="badge" [class.critical]="a.severity !== 'medium'" [class.warning]="a.severity === 'medium'">! {{ a.severity }}</span>
              <b>{{ a.evidence.label }}</b><span class="spacer"></span><span class="muted small">{{ date(a.period) }}</span></div>
            <div class="cmp"><span>Expected<b>{{ f(a.expected, a.evidence.format) }}</b></span><span>Actual<b class="neg">{{ f(a.actual, a.evidence.format) }}</b></span><span>Deviation<b>{{ Math.abs(a.score).toFixed(1) }}σ</b></span></div>
            <div class="row"><a class="btn sm signal" routerLink="/investigate" [queryParams]="{ metric: a.metric_key }">Investigate</a>
              <button class="btn sm ghost" (click)="setStatus(a, 'dismissed')">Dismiss</button><button class="btn sm ghost" (click)="setStatus(a, 'resolved')">Resolve</button></div>
          </article>
        } @empty { <p class="muted">No open anomalies.</p> }
      </div>
    </section>
  </div>
  @if (ev(); as e) { <app-evidence [title]="e.title" [body]="e.body" [calculation]="e.calc" [items]="e.items" (close)="ev.set(null)"/> }`,
  styles: [`.cols{display:grid;grid-template-columns:1.3fr 1fr;gap:24px;align-items:start}.ins,.anom{padding:18px;display:grid;gap:10px}
    .ins.warning{border-left:3px solid var(--neg)}.ins.positive{border-left:3px solid var(--pos)}.ins.critical{border-left:3px solid var(--critical)}
    .small{font-size:12px}.cmp{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.cmp span{display:grid;font-size:11.5px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.06em}
    .cmp b{font-size:20px;color:var(--ink-1);letter-spacing:-.02em;text-transform:none}.cmp b.neg{color:var(--neg)}
    @media(max-width:960px){.cols{grid-template-columns:1fr}}`],
})
export class Insights implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly insights = signal<any[]>([]);
  readonly anomalies = signal<any[]>([]);
  readonly busy = signal<'gen' | 'scan' | null>(null);
  readonly error = signal<string | null>(null);
  readonly ev = signal<any | null>(null);
  Math = Math; ago = ago; f = fmt; date = (d: string) => fmtDate(d, 'long');

  ngOnInit() { this.load(); }
  async load() {
    try {
      const [i, a] = await Promise.all([this.api.get('/insights'), this.api.get('/anomalies', { status: 'open' })]);
      this.insights.set(i.data); this.anomalies.set(a.data);
    } catch (e) { this.error.set(errorMessage(e)); }
  }
  async generate() { this.busy.set('gen'); try { this.insights.set((await this.api.post('/insights/generate')).data); } catch (e) { this.error.set(errorMessage(e)); } finally { this.busy.set(null); } }
  async scan() { this.busy.set('scan'); try { await this.api.post('/analysis/anomalies/scan'); await this.load(); } catch (e) { this.error.set(errorMessage(e)); } finally { this.busy.set(null); } }
  async setStatus(a: any, status: string) { await this.api.patch(`/anomalies/${a.id}`, { status }); this.anomalies.update(x => x.filter(y => y.id !== a.id)); }
  show(i: any) {
    const e = i.evidence ?? {};
    const items = [e.queries?.current, e.queries?.previous, e.applications?.current, e.capacity?.current, e.query, ...(Array.isArray(e.queries) ? e.queries : [])].filter(Boolean);
    this.ev.set({ title: i.title, body: i.body, calc: e.calculation ?? e.method?.replaceAll('_', ' ') ?? null, items });
  }
}
