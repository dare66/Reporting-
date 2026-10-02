import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Api } from '../../core/api.service';
import { ago } from '../../core/format';
import { Icon } from '../../shared/icon';

@Component({
  selector: 'app-notifications',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
  <div class="page">
    <header class="page-head"><div><div class="eyebrow signal">Notifications</div><h1>What happened</h1></div><button class="btn" (click)="readAll()">Mark all read</button></header>
    @for (g of groups(); track g.label) {
      <h3 class="g">{{ g.label }}</h3>
      <section class="panel">@for (n of g.items; track n.id) {
        <button class="n" [class.unread]="!n.read_at" (click)="open(n)">
          <span class="ic" [class]="n.severity"><app-icon [name]="icon(n.type)" [size]="16"/></span>
          <span class="t"><b>{{ n.title }}</b><span>{{ n.body }}</span></span><small class="muted">{{ ago(n.created_at) }}</small>
          @if (n.link) { <span class="go">{{ n.type === 'alert' || n.type === 'anomaly' ? 'Investigate' : 'Open' }} →</span> }
        </button> }</section>
    } @empty { <p class="muted">Nothing yet. Alerts, anomalies, mentions and finished reports appear here.</p> }
  </div>`,
  styles: [`.g{margin:22px 0 10px;font-size:13px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.1em}
    .n{display:flex;gap:14px;align-items:center;width:100%;padding:14px 18px;border:0;border-bottom:1px solid var(--line);background:none;text-align:left;cursor:pointer;color:inherit}
    .n:hover{background:var(--bg-3)}.n.unread b::after{content:'';display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--accent);margin-left:8px;vertical-align:middle}
    .ic{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;background:var(--bg-3);flex:none}.ic.critical,.ic.warning{background:var(--neg-soft);color:var(--neg)}.ic.positive{background:var(--pos-soft);color:var(--pos)}
    .t{display:grid;flex:1;gap:2px}.t span{color:var(--ink-2);font-size:13px}.go{color:var(--accent);font-weight:600;font-size:12.5px;white-space:nowrap}`],
})
export class Notifications implements OnInit {
  private api = inject(Api);
  private router = inject(Router);
  readonly items = signal<any[]>([]);
  ago = ago;
  readonly groups = computed(() => {
    const today = new Date().toDateString();
    const g: Record<string, any[]> = {};
    for (const n of this.items()) { const k = new Date(n.created_at).toDateString() === today ? 'Today' : 'Earlier'; (g[k] ??= []).push(n); }
    return Object.entries(g).map(([label, items]) => ({ label, items }));
  });
  ngOnInit() { this.load(); }
  async load() { this.items.set((await this.api.get('/notifications')).data); }
  async readAll() { await this.api.post('/notifications/read-all'); this.load(); }
  async open(n: any) { if (!n.read_at) await this.api.post(`/notifications/${n.id}/read`); if (n.link) this.router.navigateByUrl(n.link); else this.load(); }
  icon(t: string) { return ({ alert: 'alert', anomaly: 'warning', report: 'report', mention: 'comment' } as any)[t] ?? 'info'; }
}
