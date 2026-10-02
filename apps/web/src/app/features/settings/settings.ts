import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { Envelope, Session } from '../../core/models';
import { ago } from '../../core/format';
import { ACCENTS, Theme } from '../../core/theme.service';
import { Icon } from '../../shared/icon';

@Component({
  selector: 'app-settings',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon],
  templateUrl: './settings.html',
  styleUrl: './settings.scss',
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
  readonly sessions = signal<Session[]>([]);
  ago = ago;
  roles = () =>
    this.auth
      .user()
      ?.roles.map((r) => r.name)
      .join(', ');

  ngOnInit() {
    this.loadSessions();
  }
  async loadSessions() {
    this.sessions.set((await this.api.get<Envelope<Session[]>>('/me/sessions')).data);
  }
  async setup() {
    try {
      this.secret.set((await this.api.post<{ secret: string; otpauth_uri: string }>('/me/mfa/setup')).secret);
    } catch (e) {
      this.fail(e);
    }
  }
  async enable() {
    try {
      await this.api.post('/me/mfa/enable', { code: this.code() });
      this.msg.set('Two-factor authentication is on.');
      this.msgErr.set(false);
      this.secret.set(null);
      await this.auth.reloadProfile();
    } catch (e) {
      this.fail(e);
    }
  }
  async disable() {
    try {
      await this.api.delete('/me/mfa');
      await this.auth.reloadProfile();
    } catch (e) {
      this.fail(e);
    }
  }
  async revoke(s: Session) {
    try {
      await this.api.delete(`/me/sessions/${s.id}`);
      this.loadSessions();
    } catch (e) {
      this.fail(e);
    }
  }
  private fail(e: unknown) {
    this.msg.set(errorMessage(e));
    this.msgErr.set(true);
  }
}
