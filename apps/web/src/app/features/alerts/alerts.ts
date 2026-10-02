import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AI, Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ago, fmt } from '../../core/format';
import { Icon } from '../../shared/icon';
import { ErrorState } from '../../shared/states';

@Component({
  selector: 'app-alerts',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, RouterLink, ErrorState],
  template: `
  <div class="page">
    <header class="page-head"><div><div class="eyebrow signal">Alerts</div><h1>Know the moment it matters</h1>
      <p class="lede">Alerts evaluate governed metrics on a schedule and notify with the value, the threshold and the leading driver.</p></div>
      @if (auth.can('alerts.manage')) {
        <form class="nl panel" (submit)="$event.preventDefault(); conversational()">
          <app-icon name="ai" [size]="16"/><input [ngModel]="text()" (ngModelChange)="text.set($event)" name="t" placeholder="Alert me when SLA falls below 90%"><button class="btn sm ai" [disabled]="!text()">Create</button>
        </form>
      }
    </header>
    @if (error()) { <app-error [message]="error()!" (retry)="load()"/> }
    <section class="panel">
      <table class="table">
        <thead><tr><th>Rule</th><th>State</th><th class="r">Last value</th><th>Checks</th><th>Channels</th><th>Last evaluated</th><th></th></tr></thead>
        <tbody>@for (r of rules(); track r.id) {
          <tr>
            <td><b>{{ r.name }}</b><div class="muted small">{{ r.semantic_model?.name }} · {{ r.window.replaceAll('_', ' ') }}@if (r.created_via === 'conversation') { · <span class="ai-t">created by conversation</span> }</div></td>
            <td>@if (r.last_state === 'breached') { <span class="badge critical">! breached</span> } @else { <span class="badge good">✓ ok</span> }</td>
            <td class="r">{{ r.last_value === null ? '—' : val(r) }}</td>
            <td>every {{ r.frequency_minutes }} min</td><td>{{ r.channels.join(', ') }}</td><td class="muted">{{ ago(r.last_evaluated_at) }}</td>
            <td class="r"><div class="row"><button class="btn sm" (click)="evaluate(r)" [disabled]="busy() === r.id">{{ busy() === r.id ? 'Checking…' : 'Check now' }}</button>
              @if (auth.can('alerts.manage')) { <button class="btn sm ghost" (click)="toggle(r)">{{ r.is_active ? 'Pause' : 'Resume' }}</button><button class="btn sm ghost icon" (click)="remove(r)" aria-label="Delete"><app-icon name="trash" [size]="14"/></button> }</div></td>
          </tr>
        } @empty { <tr><td colspan="7" class="muted">No alert rules yet — describe one above.</td></tr> }</tbody>
      </table>
    </section>
    <h3 style="margin:28px 0 12px">Recent alerts</h3>
    <section class="panel">
      @for (a of history(); track a.id) {
        <div class="hist"><span class="badge critical">{{ a.rule?.name }}</span><span class="grow">{{ a.message }}</span><span class="muted small">{{ ago(a.fired_at) }}</span>
          <a class="btn sm ghost" routerLink="/investigate" [queryParams]="{ metric: a.evidence?.card?.ref }">Investigate</a>
          @if (!a.acknowledged_at) { <button class="btn sm" (click)="ack(a)">Acknowledge</button> } @else { <span class="muted small">acknowledged</span> }</div>
      } @empty { <p class="muted" style="padding:16px">No alerts have fired.</p> }
    </section>
  </div>`,
  styles: [`.nl{display:flex;align-items:center;gap:8px;padding:6px 6px 6px 14px;min-width:min(440px,100%);color:var(--ai)}.nl input{flex:1;border:0;background:none;outline:none;color:var(--ink-1);height:30px}
    .small{font-size:12px}.ai-t{color:var(--ai)}.hist{display:flex;gap:12px;align-items:center;padding:12px 16px;border-bottom:1px solid var(--line)}.grow{flex:1}`],
})
export class Alerts implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly rules = signal<any[]>([]);
  readonly history = signal<any[]>([]);
  readonly busy = signal<string | null>(null);
  readonly error = signal<string | null>(null);
  readonly text = signal('');
  ago = ago;

  ngOnInit() { this.load(); }
  async load() {
    try { const [r, h] = await Promise.all([this.api.get('/alert-rules'), this.api.get('/alerts')]); this.rules.set(r.data); this.history.set(h.data); }
    catch (e) { this.error.set(errorMessage(e)); }
  }
  val(r: any) { return r.threshold < 1.5 && r.last_value <= 1.5 ? fmt(r.last_value, 'percent') : fmt(r.last_value); }
  async evaluate(r: any) { this.busy.set(r.id); try { await this.api.post(`/alert-rules/${r.id}/evaluate`); await this.load(); } finally { this.busy.set(null); } }
  async toggle(r: any) { await this.api.patch(`/alert-rules/${r.id}`, { is_active: !r.is_active }); this.load(); }
  async remove(r: any) { await this.api.delete(`/alert-rules/${r.id}`); this.load(); }
  async ack(a: any) { await this.api.post(`/alerts/${a.id}/acknowledge`); this.load(); }

  /** Conversational creation goes through the AI analyst's alert agent. */
  async conversational() {
    const res = await fetch(`${AI}/chat`, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${this.auth.accessToken()}` }, body: JSON.stringify({ question: this.text() }) });
    const body = await res.json();
    if (!res.ok || body.intent !== 'alert') this.error.set(body.answer ?? body.detail ?? 'I could not turn that into an alert.');
    this.text.set('');
    this.load();
  }
}
