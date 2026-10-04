import { CdkTrapFocus } from '@angular/cdk/a11y';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../../core/api.service';
import { Auth } from '../../../core/auth.service';
import { ago, fmtDate } from '../../../core/format';
import { ActivityEntry, AdminUser, AdminUserDetail, DataScope, Department, Envelope, Role } from '../../../core/models';
import { Icon } from '../../../shared/icon';
import { Scrim } from '../../../shared/scrim';
import { OneTimeSecret } from './one-time-secret';
import { ScopeEditor } from './scope-editor';

/** Account actions that need a second click to confirm. */
type Pending = 'password' | 'mfa' | 'sessions' | 'status' | null;

/** Plain-language names for audit actions on a person's timeline. */
const ACTIONS: Record<string, string> = {
  'auth.login': 'Signed in',
  'auth.logout': 'Signed out',
  'auth.password_changed': 'Changed their password',
  'auth.password_change': 'Password change attempt',
  'auth.mfa_enabled': 'Turned on two-factor authentication',
  'auth.mfa_disabled': 'Turned off two-factor authentication',
  'auth.profile_updated': 'Updated their profile',
  'auth.session_revoked': 'Signed out a device',
  'query.run': 'Ran a governed query',
  'ai.run': 'Asked the AI analyst',
  'report.export': 'Exported a report',
  'dashboard.created': 'Created a dashboard',
};

/** A person's full record for administrators: profile, access, security and activity. */
@Component({
  selector: 'app-user-drawer',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CdkTrapFocus, FormsModule, Scrim, Icon, OneTimeSecret, ScopeEditor],
  templateUrl: './user-drawer.html',
  styleUrl: './user-drawer.scss',
})
export class UserDrawer implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly userId = input.required<string>();
  readonly roles = input.required<Role[]>();
  readonly departments = input.required<Department[]>();
  readonly changed = output<AdminUser>();
  readonly dismiss = output();

  readonly user = signal<AdminUserDetail | null>(null);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);
  readonly pending = signal<Pending>(null);
  readonly busy = signal(false);
  readonly temporaryPassword = signal<string | null>(null);

  // Editable profile
  readonly name = signal('');
  readonly title = signal('');
  readonly email = signal('');
  readonly departmentId = signal('');
  readonly roleKeys = signal<string[]>([]);
  readonly scope = signal<DataScope | null>(null);

  readonly isSelf = computed(() => this.user()?.id === this.auth.user()?.id);
  readonly dirty = computed(() => {
    const u = this.user();
    if (!u) return false;
    return (
      this.name() !== u.name ||
      this.title() !== (u.title ?? '') ||
      this.email() !== u.email ||
      this.departmentId() !== (u.department_id ?? '') ||
      [...this.roleKeys()].sort().join() !==
        u.roles
          .map((r) => r.key)
          .sort()
          .join() ||
      JSON.stringify(this.scope()?.country_codes ?? null) !== JSON.stringify(u.data_scope?.country_codes ?? null)
    );
  });
  ago = ago;
  date = (d: string | null) => fmtDate(d, 'time');

  ngOnInit() {
    this.load();
  }

  async load() {
    try {
      const u = (await this.api.get<Envelope<AdminUserDetail>>(`/admin/users/${this.userId()}`)).data;
      this.user.set(u);
      this.name.set(u.name);
      this.title.set(u.title ?? '');
      this.email.set(u.email);
      this.departmentId.set(u.department_id ?? '');
      this.roleKeys.set(u.roles.map((r) => r.key));
      this.scope.set(u.data_scope?.country_codes ? { country_codes: u.data_scope.country_codes } : null);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  toggleRole(key: string) {
    this.roleKeys.update((k) => (k.includes(key) ? k.filter((x) => x !== key) : [...k, key]));
  }

  activityLabel(a: ActivityEntry) {
    const base = ACTIONS[a.action] ?? a.action.replace(/[._]/g, ' ');
    return a.decision === 'deny' || a.result === 'failure' ? `${base} (refused)` : base;
  }

  async save() {
    await this.run(async () => {
      const u = await this.patch({
        name: this.name().trim(),
        title: this.title().trim() || null,
        email: this.email().trim(),
        department_id: this.departmentId() || null,
        roles: this.roleKeys(),
        attributes: this.scope() ?? {},
      });
      this.notice.set(`Saved changes to ${u.name}.`);
    });
  }

  /** First click asks; the second, within the same panel, acts. */
  async confirm(action: Exclude<Pending, null>) {
    if (this.pending() !== action) {
      this.pending.set(action);
      return;
    }
    this.pending.set(null);
    const id = this.userId();
    await this.run(async () => {
      if (action === 'password') {
        const res = await this.api.post<Envelope<{ temporary_password: string }>>(`/admin/users/${id}/reset-password`);
        this.temporaryPassword.set(res.data.temporary_password);
        this.notice.set('Password reset. They were signed out everywhere.');
      } else if (action === 'mfa') {
        await this.api.delete(`/admin/users/${id}/mfa`);
        this.notice.set('Two-factor authentication reset. They will enrol again when required.');
      } else if (action === 'sessions') {
        const res = await this.api.delete<Envelope<{ revoked: number }>>(`/admin/users/${id}/sessions`);
        this.notice.set(`Signed out of ${res.data.revoked} device${res.data.revoked === 1 ? '' : 's'}.`);
      } else {
        const status = this.user()?.status === 'active' ? 'suspended' : 'active';
        await this.patch({ status });
        this.notice.set(status === 'suspended' ? 'Account suspended and signed out.' : 'Account reactivated.');
      }
      await this.load();
      const fresh = this.user();
      if (fresh) this.changed.emit(fresh);
    });
  }

  private async patch(body: Record<string, unknown>): Promise<AdminUser> {
    const u = (await this.api.patch<Envelope<AdminUser>>(`/admin/users/${this.userId()}`, body)).data;
    this.changed.emit(u);
    await this.load();
    return u;
  }

  private async run(work: () => Promise<void>) {
    this.busy.set(true);
    this.error.set(null);
    this.notice.set(null);
    try {
      await work();
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }
}
