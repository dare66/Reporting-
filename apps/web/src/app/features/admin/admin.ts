import { ChangeDetectionStrategy, Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ago } from '../../core/format';
import { ACCENTS, Theme } from '../../core/theme.service';
import { Icon } from '../../shared/icon';
import { ErrorState } from '../../shared/states';

@Component({
  selector: 'app-admin',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, ErrorState],
  template: `
  <div class="page">
    <header class="page-head"><div><div class="eyebrow signal">Administration</div><h1>{{ auth.user()?.organisation?.name }}</h1>
      <p class="lede">People, roles, branding and platform health for your organisation.</p></div>
      <div class="seg">@for (t of tabs(); track t.key) { <button [class.on]="tab() === t.key" (click)="go(t.key)">{{ t.label }}</button> }</div></header>
    @if (error()) { <app-error [message]="error()!" (retry)="error.set(null)"/> }

    @if (tab() === 'users') {
      <div class="row wrap" style="margin-bottom:12px"><input class="input" style="max-width:280px" placeholder="Search people" [ngModel]="q()" (ngModelChange)="q.set($event); loadUsers()">
        <span class="spacer"></span><button class="btn signal" (click)="newUser.set(!newUser())"><app-icon name="plus" [size]="16"/> Invite user</button></div>
      @if (newUser()) {
        <form class="panel form" (submit)="$event.preventDefault(); createUser()">
          <input class="input" placeholder="Full name" [(ngModel)]="u.name" name="n" required><input class="input" type="email" placeholder="Email" [(ngModel)]="u.email" name="e" required>
          <input class="input" placeholder="Title" [(ngModel)]="u.title" name="t"><input class="input" type="password" placeholder="Initial password (12+ chars)" [(ngModel)]="u.password" name="p" required minlength="12">
          <select class="select" [(ngModel)]="u.role" name="r">@for (r of roles(); track r.id) { <option [value]="r.key">{{ r.name }}</option> }</select>
          <input class="input" placeholder="Data scope: country codes, e.g. CN, IN (optional)" [(ngModel)]="u.scope" name="s">
          <button class="btn signal">Create</button>
        </form>
      }
      <section class="panel scroll-x"><table class="table"><thead><tr><th>Person</th><th>Role</th><th>Experience</th><th>Data scope</th><th>MFA</th><th>Last sign-in</th><th>Status</th></tr></thead>
        <tbody>@for (x of users(); track x.id) {
          <tr><td><b>{{ x.name }}</b><div class="muted small">{{ x.email }} · {{ x.title }}</div></td>
            <td><select class="select sm-sel" [ngModel]="x.roles[0]?.key" (ngModelChange)="setRole(x, $event)">@for (r of roles(); track r.id) { <option [value]="r.key">{{ r.name }}</option> }</select></td>
            <td><span class="badge">{{ x.experience }}</span></td><td class="small">{{ x.data_scope?.country_codes?.join(', ') ?? 'All data' }}</td>
            <td>@if (x.mfa_enabled) { <span class="badge good">✓ on</span> } @else { <span class="muted small">off</span> }</td><td class="muted small">{{ ago(x.last_login_at) }}</td>
            <td><button class="btn sm" [class.ghost]="x.status === 'active'" (click)="toggleStatus(x)" [disabled]="x.id === auth.user()?.id">{{ x.status === 'active' ? 'Suspend' : 'Reactivate' }}</button></td></tr>
        }</tbody></table></section>
    }

    @if (tab() === 'roles') {
      <section class="panel scroll-x"><table class="table matrix"><thead><tr><th>Permission</th>@for (r of roles(); track r.id) { <th class="c">{{ r.name }}</th> }</tr></thead>
        <tbody>@for (p of permissions(); track p.id) { <tr><td><b class="mono">{{ p.key }}</b><div class="muted small">{{ p.description }}</div></td>
          @for (r of roles(); track r.id) { <td class="c">@if (has(r, p.key)) { <span class="yes" aria-label="granted">●</span> } @else { <span class="no">·</span> }</td> }</tr> }</tbody></table></section>
    }

    @if (tab() === 'org') {
      <section class="panel form-grid">
        <label class="field">Organisation name<input class="input" [(ngModel)]="org.name"></label>
        <label class="field">Currency<input class="input" [(ngModel)]="org.currency" maxlength="3"></label>
        <label class="field">Time zone<input class="input" [(ngModel)]="org.timezone"></label>
        <label class="field">Brand accent<input class="input" type="color" [(ngModel)]="org.accent"></label>
        <div class="field" style="grid-column:1/-1">Platform theme<div class="row wrap">@for (a of accents; track a) { <button class="chip" [class.on]="theme.accent() === a" (click)="theme.setAccent(a)">{{ a }}</button> }</div></div>
        <div class="row" style="grid-column:1/-1"><span class="spacer"></span>@if (saved()) { <span class="small ok">✓ saved</span> }<button class="btn signal" (click)="saveOrg()">Save</button></div>
      </section>
      <h3 style="margin:24px 0 12px">Feature flags</h3>
      <section class="panel">@for (f of flags(); track f.key) {
        <div class="flag"><span class="mono">{{ f.key }}</span><span class="spacer"></span><label class="switch"><input type="checkbox" [checked]="f.enabled" (change)="setFlag(f, $any($event.target).checked)"><span></span></label></div> }</section>
    }

    @if (tab() === 'health' && health(); as h) {
      <p class="muted small">Live · checked {{ ago(h.checked_at) }} · refreshes every 15 s</p>
      <div class="health">@for (c of components(); track c.key) {
        <div class="hc panel"><span class="status" [class]="c.status"></span><div><b>{{ c.label }}</b><div class="muted small">{{ c.detail }}</div></div><span class="spacer"></span>
          <span class="small">{{ c.status.replace('_', ' ') }}{{ c.latency_ms !== null && c.latency_ms !== undefined ? ' · ' + c.latency_ms + ' ms' : '' }}</span></div> }</div>
      <h3 style="margin:24px 0 12px">Query performance (last hour)</h3>
      <section class="tiles panel"><span>Queries<b>{{ h.query_performance.last_hour_queries }}</b></span><span>Average<b>{{ h.query_performance.avg_ms }} ms</b></span>
        <span>p95<b>{{ h.query_performance.p95_ms }} ms</b></span><span>Cache hit rate<b>{{ h.query_performance.cache_hit_rate === null ? '—' : (h.query_performance.cache_hit_rate * 100).toFixed(0) + '%' }}</b></span><span>Failures<b>{{ h.query_performance.failures }}</b></span></section>
    }
  </div>`,
  styles: [`.small{font-size:12px}.ok{color:var(--pos)}.sm-sel{height:30px;font-size:12.5px;width:auto}
    .form{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:16px;margin-bottom:16px}
    .matrix th.c,.matrix td.c{text-align:center}.yes{color:var(--accent)}.no{color:var(--ink-3)}
    .form-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;padding:20px}
    .flag{display:flex;align-items:center;padding:12px 18px;border-bottom:1px solid var(--line)}
    .switch{position:relative;width:38px;height:22px}.switch input{opacity:0;width:0;height:0}.switch span{position:absolute;inset:0;border-radius:11px;background:var(--bg-3);transition:.2s;cursor:pointer}
    .switch span::after{content:'';position:absolute;left:3px;top:3px;width:16px;height:16px;border-radius:50%;background:var(--ink-2);transition:.2s}.switch input:checked+span{background:var(--accent)}.switch input:checked+span::after{left:19px;background:#140d02}
    .health{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px}.hc{display:flex;gap:12px;align-items:center;padding:14px 16px}
    .status{width:10px;height:10px;border-radius:50%;background:var(--ink-3);flex:none}.status.operational{background:var(--good);box-shadow:0 0 0 4px color-mix(in srgb,var(--good) 20%,transparent)}.status.down{background:var(--critical)}.status.unknown{background:var(--warning)}
    .tiles{display:grid;grid-template-columns:repeat(5,1fr);padding:18px}.tiles span{display:grid;font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.06em}.tiles b{font-size:22px;color:var(--ink-1);text-transform:none}
    @media(max-width:960px){.form,.form-grid{grid-template-columns:1fr}.tiles{grid-template-columns:repeat(2,1fr)}}`],
})
export class Admin implements OnInit, OnDestroy {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly theme = inject(Theme);
  readonly accents = ACCENTS;
  readonly tabs = computed(() => [
    ...(this.auth.can('admin.users') ? [{ key: 'users', label: 'Users' }, { key: 'roles', label: 'Roles' }] : []),
    ...(this.auth.can('admin.org') ? [{ key: 'org', label: 'Organisation' }] : []),
    ...(this.auth.can('admin.system') ? [{ key: 'health', label: 'System health' }] : []),
  ]);
  readonly tab = signal('users');
  readonly users = signal<any[]>([]);
  readonly roles = signal<any[]>([]);
  readonly permissions = signal<any[]>([]);
  readonly flags = signal<any[]>([]);
  readonly health = signal<any | null>(null);
  readonly q = signal('');
  readonly newUser = signal(false);
  readonly saved = signal(false);
  readonly error = signal<string | null>(null);
  u: any = { role: 'viewer' };
  org: any = {};
  private timer: any;
  ago = ago;

  readonly components = computed(() => {
    const labels: Record<string, string> = { api: 'API', database: 'Metadata database', analytics_store: 'Analytical store', redis: 'Redis cache', ai_service: 'AI services',
      report_workers: 'Report workers', data_pipelines: 'Data pipelines', storage: 'Storage', kafka: 'Kafka' };
    return Object.entries(this.health()?.components ?? {}).map(([key, v]: any) => ({ key, label: labels[key] ?? key, ...v }));
  });

  ngOnInit() { this.go(this.tabs()[0]?.key ?? 'health'); }
  ngOnDestroy() { clearInterval(this.timer); }

  async go(t: string) {
    this.tab.set(t);
    clearInterval(this.timer);
    try {
      if (t === 'users') { await this.loadUsers(); this.roles.set((await this.api.get('/admin/roles')).data); }
      if (t === 'roles') { const [r, p] = await Promise.all([this.api.get('/admin/roles'), this.api.get('/admin/permissions')]); this.roles.set(r.data); this.permissions.set(p.data); }
      if (t === 'org') { const o = (await this.api.get('/admin/organisation')).data; this.org = { name: o.name, currency: o.currency, timezone: o.timezone, accent: o.branding?.accent ?? '#e8b04b' }; this.flags.set((await this.api.get('/admin/feature-flags')).data); }
      if (t === 'health') { const tick = async () => this.health.set((await this.api.get('/admin/health')).data); await tick(); this.timer = setInterval(tick, 15000); }
    } catch (e) { this.error.set(errorMessage(e)); }
  }
  async loadUsers() { this.users.set((await this.api.get('/admin/users', { q: this.q() })).data); }
  has(r: any, key: string) { return r.permissions.some((p: any) => p.key === key || p.key === '*'); }
  async setRole(x: any, role: string) { try { await this.api.patch(`/admin/users/${x.id}`, { roles: [role] }); this.loadUsers(); } catch (e) { this.error.set(errorMessage(e)); } }
  async toggleStatus(x: any) { await this.api.patch(`/admin/users/${x.id}`, { status: x.status === 'active' ? 'suspended' : 'active' }); this.loadUsers(); }
  async createUser() {
    try {
      const scope = this.u.scope ? { country_codes: String(this.u.scope).split(',').map((s: string) => s.trim().toUpperCase()).filter(Boolean) } : {};
      await this.api.post('/admin/users', { name: this.u.name, email: this.u.email, title: this.u.title, password: this.u.password, roles: [this.u.role], attributes: scope });
      this.u = { role: 'viewer' }; this.newUser.set(false); this.loadUsers();
    } catch (e) { this.error.set(errorMessage(e)); }
  }
  async saveOrg() {
    await this.api.patch('/admin/organisation', { name: this.org.name, currency: this.org.currency, timezone: this.org.timezone, branding: { accent: this.org.accent } });
    this.saved.set(true);
    this.auth.reloadProfile();
  }
  async setFlag(f: any, enabled: boolean) { await this.api.put(`/admin/feature-flags/${f.key}`, { enabled }); this.flags.set((await this.api.get('/admin/feature-flags')).data); }
}
