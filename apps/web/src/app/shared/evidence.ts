import { ChangeDetectionStrategy, Component, input, output, signal } from '@angular/core';
import { fmtDate } from '../core/format';
import { Icon } from './icon';

/** "View evidence": the queries, filters, periods and calculation behind a statement. */
@Component({
  selector: 'app-evidence',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    <div class="scrim" (click)="close.emit()"></div>
    <aside class="sheet glass" role="dialog" aria-label="Evidence">
      <div class="grab"></div>
      <header class="row"><div><div class="eyebrow ai">Evidence</div><h3>{{ title() }}</h3></div><span class="spacer"></span>
        <button class="btn icon ghost" (click)="close.emit()" aria-label="Close"><app-icon name="close"/></button></header>
      @if (body()) { <p class="secondary">{{ body() }}</p> }
      @if (calculation()) { <div class="calc"><span class="eyebrow">Calculation</span><div>{{ calculation() }}</div></div> }
      <div class="eyebrow" style="margin-top:8px">Queries ({{ items().length }})</div>
      @for (e of items(); track $index; let i = $index) {
        <div class="item">
          <div class="row"><b>{{ e.label || 'Query ' + (i + 1) }}</b><span class="spacer"></span>
            @if (e.cached) { <span class="badge">cached</span> }
            <span class="mono muted">#{{ (e.query_hash || '').slice(0, 10) }}</span></div>
          @if (e.query || e.semantic_query) {
            @let q = e.query || e.semantic_query;
            <div class="kv"><span>Model</span><span>{{ q.model }}</span><span>Metrics</span><span>{{ (q.metrics || q.measures || []).join(', ') }}</span>
              @if (q.dimensions?.length) { <span>By</span><span>{{ q.dimensions.join(', ') }}</span> }
              @if (q.filters?.length) { <span>Filters</span><span>{{ filters(q.filters) }}</span> }
              @if (q.time?.range) { <span>Period</span><span>{{ period(q.time.range) }}</span> }</div>
          }
          @if (e.sql) { <button class="btn sm ghost" (click)="toggle(i)">{{ open() === i ? 'Hide' : 'Show' }} SQL</button>
            @if (open() === i) { <pre class="mono">{{ e.sql }}</pre> } }
          <div class="muted small">Executed {{ when(e.executed_at) }}@if (e.row_count !== undefined) { · {{ e.row_count }} rows }</div>
        </div>
      } @empty { <p class="muted">No query evidence was recorded for this item.</p> }
      <p class="muted small foot"><app-icon name="lock" [size]="13"/> Queries ran under your permissions, with row-level security applied.</p>
    </aside>`,
  styles: [`
    .scrim{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:80;animation:rise .2s}
    .sheet{position:fixed;z-index:81;top:12px;right:12px;bottom:12px;width:min(520px,calc(100vw - 24px));overflow:auto;padding:20px 22px;border:1px solid var(--line-2);border-radius:var(--r-xl);display:grid;gap:12px;align-content:start;animation:slide .3s var(--ease)}
    @keyframes slide{from{transform:translateX(24px);opacity:0}}
    .grab{display:none}
    .calc{padding:12px;border-radius:var(--r-md);background:var(--bg-3);display:grid;gap:4px}
    .item{display:grid;gap:8px;padding:12px;border:1px solid var(--line);border-radius:var(--r-md)}
    .kv{display:grid;grid-template-columns:80px 1fr;gap:4px 12px;font-size:12.5px}.kv span:nth-child(odd){color:var(--ink-3)}
    pre{margin:0;white-space:pre-wrap;word-break:break-word;background:var(--bg-0);padding:10px;border-radius:8px;color:var(--ink-2);max-height:220px;overflow:auto}
    .small{font-size:11.5px}.foot{display:flex;gap:6px;align-items:center}
    @media (max-width:720px){.sheet{top:auto;left:0;right:0;bottom:0;width:100%;max-height:85vh;border-radius:var(--r-xl) var(--r-xl) 0 0;animation:up .3s var(--ease)}
      @keyframes up{from{transform:translateY(40px);opacity:0}} .grab{display:block;width:40px;height:4px;border-radius:2px;background:var(--line-2);margin:0 auto}}`],
})
export class Evidence {
  readonly title = input('Supporting evidence');
  readonly body = input<string | null>(null);
  readonly calculation = input<string | null>(null);
  readonly items = input<any[]>([]);
  readonly close = output<void>();
  readonly open = signal<number | null>(null);
  toggle(i: number) { this.open.set(this.open() === i ? null : i); }
  when = (d: string) => fmtDate(d, 'time');
  period = (r: any) => (typeof r === 'string' ? r.replace(/_/g, ' ') : `${r.from} → ${r.to}${r.label ? ' (' + r.label + ')' : ''}`);
  filters = (f: any[]) => f.map(x => `${x.dimension} ${x.op} ${Array.isArray(x.value) ? x.value.join(', ') : x.value}`).join('; ');
}
