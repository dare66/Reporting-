import { CdkTrapFocus } from '@angular/cdk/a11y';
import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../../core/api.service';
import { AdminUser, DataScope, Department, Envelope, Role, SecurityPolicy } from '../../../core/models';
import { Icon } from '../../../shared/icon';
import { Scrim } from '../../../shared/scrim';
import { OneTimeSecret, generatePassword } from './one-time-secret';
import { ScopeEditor } from './scope-editor';

/** Adds a person: profile, role, department and data scope, with a one-time initial password. */
@Component({
  selector: 'app-invite-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CdkTrapFocus, FormsModule, Scrim, Icon, OneTimeSecret, ScopeEditor],
  templateUrl: './invite-dialog.html',
  styleUrl: './user-forms.scss',
})
export class InviteDialog {
  private api = inject(Api);
  readonly roles = input.required<Role[]>();
  readonly departments = input.required<Department[]>();
  readonly policy = input<SecurityPolicy | null>(null);
  readonly created = output<AdminUser>();
  readonly dismiss = output();

  readonly name = signal('');
  readonly email = signal('');
  readonly title = signal('');
  readonly role = signal('viewer');
  readonly departmentId = signal('');
  readonly scope = signal<DataScope | null>(null);
  readonly password = signal(generatePassword());
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly done = signal<{ user: AdminUser; password: string } | null>(null);

  readonly minLength = computed(() => this.policy()?.password_min_length ?? 12);
  readonly domainHint = computed(() => {
    const d = this.policy()?.allowed_email_domains ?? [];
    return d.length ? `Allowed domains: ${d.join(', ')}` : '';
  });
  readonly valid = computed(
    () =>
      this.name().trim().length > 1 &&
      /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(this.email()) &&
      this.password().length >= this.minLength(),
  );

  regenerate() {
    this.password.set(generatePassword());
  }

  async create() {
    if (!this.valid()) return;
    this.saving.set(true);
    this.error.set(null);
    try {
      const user = (
        await this.api.post<Envelope<AdminUser>>('/admin/users', {
          name: this.name().trim(),
          email: this.email().trim(),
          title: this.title().trim() || null,
          password: this.password(),
          roles: [this.role()],
          department_id: this.departmentId() || null,
          attributes: this.scope() ?? {},
        })
      ).data;
      this.done.set({ user, password: this.password() });
      this.created.emit(user);
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.saving.set(false);
    }
  }
}
