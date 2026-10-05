import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AI, Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ProjectScope } from '../../core/project-scope.service';
import { AlertEvent, AlertRule, Envelope } from '../../core/models';
import { ago, fmt } from '../../core/format';
import { Icon } from '../../shared/icon';
import { ErrorState } from '../../shared/states';

@Component({
  selector: 'app-alerts',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, RouterLink, ErrorState],
  templateUrl: './alerts.html',
  styleUrl: './alerts.scss',
})
export class Alerts implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  private scope = inject(ProjectScope);
  readonly rules = signal<AlertRule[]>([]);
  readonly history = signal<AlertEvent[]>([]);
  readonly busy = signal<string | null>(null);
  readonly error = signal<string | null>(null);
  readonly text = signal('');
  ago = ago;

  ngOnInit() {
    this.load();
  }
  async load() {
    try {
      const [r, h] = await Promise.all([
        this.api.get<Envelope<AlertRule[]>>('/alert-rules'),
        this.api.get<Envelope<AlertEvent[]>>('/alerts'),
      ]);
      this.rules.set(r.data);
      this.history.set(h.data);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
  /** The rule's last value in its metric's own units. */
  val(r: AlertRule) {
    return fmt(r.last_value, r.metric?.format);
  }
  evaluate(r: AlertRule) {
    return this.run(r.id, () => this.api.post(`/alert-rules/${r.id}/evaluate`));
  }
  toggle(r: AlertRule) {
    return this.run(r.id, () => this.api.patch(`/alert-rules/${r.id}`, { is_active: !r.is_active }));
  }
  remove(r: AlertRule) {
    return this.run(r.id, () => this.api.delete(`/alert-rules/${r.id}`));
  }
  ack(a: AlertEvent) {
    return this.run(a.id, () => this.api.post(`/alerts/${a.id}/acknowledge`));
  }

  /** Runs an action with a busy marker on the row, then reloads. */
  private async run(id: string, action: () => Promise<unknown>) {
    this.busy.set(id);
    try {
      await action();
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(null);
    }
  }

  /** Conversational creation goes through the AI analyst's alert agent. */
  async conversational() {
    const res = await fetch(`${AI}/chat`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${this.auth.accessToken()}`,
        ...this.scope.headers(),
      },
      body: JSON.stringify({ question: this.text() }),
    });
    const body = (await res.json()) as { intent?: string; answer?: string; detail?: string };
    if (!res.ok || body.intent !== 'alert')
      this.error.set(body.answer ?? body.detail ?? 'I could not turn that into an alert.');
    this.text.set('');
    this.load();
  }
}
