import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ago } from '../../core/format';
import { ACCENTS, Theme } from '../../core/theme.service';
import { Icon } from '../../shared/icon';

@Component({
  selector: 'app-settings',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon],
  template: `
  <div class="page">
    <header class="page-head"><div><div class="eyebrow signal">Profile &amp; settings</div><h1>{{ auth.user()?.name }}</h1>
      <p class="lede">{{ auth.user()?.title }} · {{ auth.user()?.department }} · {{ roles() }}</p></div>
      <button class="btn" (click)="auth.logout()"><app-icon name="logout" [size]="16"/> Sign out</button></header>
    <div class="cols">
      <section class="panel pad">
        <h3>Appearance</h3>
        <div class="seg">@for (m of ['dark', 'light', 'system']; track m) { <button [class.on]="theme.mode() === m" (click)="theme.setMode($any(m))">{{ m === 'dark' ? 'Dark intelligence' : m === 'light' ? 'Light intelligence' : 'System' }}</button> }</div>
        <div class="row wrap">@for (a of accents; track a) { <button class="chip" [class.on]="theme.accent() === a" (click)="theme.setAccent(a)">{{ a }}</button> }</div>
        <p class="muted small">Data colours stay constant across themes: they are validated for colour-blind separation and contrast.</p>
        <h3 style="margin-top:12px">Your data access</h3>
        @if (auth.user()?.data_scope?.['country_codes']; as cc) { <p>Row-level security limits you to: <b>{{ cc.join(', ') }}</b></p> } @else { <p class="muted">You see all rows permitted by your role.</p> }
      </section>
      <section class="panel pad">
        <h3>Two-factor authentication</h3>
        @if (auth.user()?.mfa_enabled) {
          <p><span class="badge good">✓ enabled</span> Your account requires an authenticator code at sign-in.</p>
          <button class="btn ghost" (click)="disable()">Disable</button>
        } @else if (secret()) {
          <p>Add this key to your authenticator app, then enter the 6-digit code.</p>
          <code class="mono key">{{ secret() }}</code>
          <div class="row"><input class="input" inputmode="numeric" maxlength="6" [ngModel]="code()" (ngModelChange)="code.set($event)" placeholder="123456" style="max-width:140px"><button class="btn signal" (click)="enable()">Verify</button></div>
        } @else { <button class="btn signal" (click)="setup()"><app-icon name="lock" [size]="16"/> Set up authenticator</button> }
        @if (msg()) { <p class="small" [class.neg]="msgErr()">{{ msg() }}</p> }
        <h3 style="margin-top:12px">Active sessions</h3>
        @for (s of sessions(); track s.id) { <div class="sess"><div><b>{{ s.device?.slice(0, 60) ?? 'Unknown device' }}</b><div class="muted small">{{ s.ip_address }} · started {{ ago(s.created_at) }}</div></div><button class="btn sm ghost" (click)="revoke(s)">Revoke</button></div> }
      </section>
    </div>
  </div>`,
  styles: [`.cols{display:grid;grid-template-columns:1fr 1fr;gap:20px}.pad{padding:22px;display:grid;gap:14px;align-content:start}.small{font-size:12px}.neg{color:var(--neg)}
    .key{padding:10px;border-radius:8px;background:var(--bg-3);word-break:break-all}.sess{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--line)}
    @media(max-width:960px){.cols{grid-template-columns:1fr}}`],
})
export class Settings implements OnInit {
  readonly auth = inject(Auth);
  readonly theme = inject(Theme);
  private api = inject(Api);
  readonly accents = ACCENTS;
  readonly secret = signal<string | null>(null);
  readonly code = signal('');
  readonly msg = signal<string | null>(null);
  readonly msgErr = signal(false);
  readonly sessions = signal<any[]>([]);
  ago = ago;
  roles = () => this.auth.user()?.roles.map(r => r.name).join(', ');

  ngOnInit() { this.loadSessions(); }
  async loadSessions() { this.sessions.set((await this.api.get('/me/sessions')).data); }
  async setup() { this.secret.set((await this.api.post('/me/mfa/setup')).secret); }
  async enable() {
    try { await this.api.post('/me/mfa/enable', { code: this.code() }); this.msg.set('Two-factor authentication is on.'); this.msgErr.set(false); this.secret.set(null); await this.auth.reloadProfile(); }
    catch (e) { this.msg.set(errorMessage(e)); this.msgErr.set(true); }
  }
  async disable() { await this.api.delete('/me/mfa'); await this.auth.reloadProfile(); }
  async revoke(s: any) { await this.api.delete(`/me/sessions/${s.id}`); this.loadSessions(); }
}
