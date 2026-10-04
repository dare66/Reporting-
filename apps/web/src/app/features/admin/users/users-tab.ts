import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../../core/api.service';
import { Auth } from '../../../core/auth.service';
import { ago } from '../../../core/format';
import { AdminUser, Department, Envelope, Role, SecurityPolicy } from '../../../core/models';
import { Icon } from '../../../shared/icon';
import { InviteDialog } from './invite-dialog';
import { UserDrawer } from './user-drawer';

/** People in the organisation: find, add and open anyone for full account management. */
@Component({
  selector: 'app-users-tab',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, InviteDialog, UserDrawer],
  templateUrl: './users-tab.html',
  styleUrl: './users-tab.scss',
})
export class UsersTab implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly roles = input.required<Role[]>();
  readonly departments = input.required<Department[]>();
  readonly policy = input<SecurityPolicy | null>(null);

  readonly users = signal<AdminUser[]>([]);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly q = signal('');
  readonly role = signal('');
  readonly status = signal('');
  readonly department = signal('');
  readonly inviting = signal(false);
  readonly openId = signal<string | null>(null);
  private timer?: ReturnType<typeof setTimeout>;
  ago = ago;

  readonly summary = computed(() => {
    const u = this.users();
    return {
      total: u.length,
      suspended: u.filter((x) => x.status !== 'active').length,
      withoutMfa: u.filter((x) => !x.mfa_enabled).length,
      pending: u.filter((x) => x.must_change_password).length,
    };
  });

  ngOnInit() {
    this.load();
  }

  /** Text search waits for a pause in typing; the selects apply at once. */
  search(text: string) {
    this.q.set(text);
    clearTimeout(this.timer);
    this.timer = setTimeout(() => this.load(), 250);
  }
  setFilter(which: 'role' | 'status' | 'department', value: string) {
    this[which].set(value);
    this.load();
  }

  async load() {
    this.loading.set(true);
    try {
      const params = { q: this.q(), role: this.role(), status: this.status(), department_id: this.department() };
      this.users.set((await this.api.get<Envelope<AdminUser[]>>('/admin/users', params)).data);
      this.error.set(null);
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.loading.set(false);
    }
  }

  replace(u: AdminUser) {
    this.users.update((list) => list.map((x) => (x.id === u.id ? { ...x, ...u } : x)));
  }
  added() {
    this.load();
  }
  initials(name: string) {
    const parts = name.split(' ');
    return (parts[0]?.charAt(0) ?? '') + (parts.length > 1 ? (parts.at(-1)?.charAt(0) ?? '') : '');
  }
}
