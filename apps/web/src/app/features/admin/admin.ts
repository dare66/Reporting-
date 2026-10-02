import { ChangeDetectionStrategy, Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../core/api.service';
import { AdminUser, Envelope, FeatureFlag, Organisation, Permission, Role, SystemHealth } from '../../core/models';
import { Auth } from '../../core/auth.service';
import { ago } from '../../core/format';
import { ACCENTS, Theme } from '../../core/theme.service';
import { Icon } from '../../shared/icon';
import { ErrorState } from '../../shared/states';

const DEFAULT_ACCENT = '#e8b04b';

interface NewUserForm {
  name: string;
  email: string;
  title: string;
  password: string;
  role: string;
  /** Comma-separated country codes limiting the user's rows (attribute-based access). */
  scope: string;
}

const EMPTY_USER: NewUserForm = { name: '', email: '', title: '', password: '', role: 'viewer', scope: '' };

interface OrganisationForm {
  name: string;
  currency: string;
  timezone: string;
  accent: string;
}

@Component({
  selector: 'app-admin',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, ErrorState],
  templateUrl: './admin.html',
  styleUrl: './admin.scss',
})
export class Admin implements OnInit, OnDestroy {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly theme = inject(Theme);
  readonly accents = ACCENTS;
  readonly tabs = computed(() => [
    ...(this.auth.can('admin.users')
      ? [
          { key: 'users', label: 'Users' },
          { key: 'roles', label: 'Roles' },
        ]
      : []),
    ...(this.auth.can('admin.org') ? [{ key: 'org', label: 'Organisation' }] : []),
    ...(this.auth.can('admin.system') ? [{ key: 'health', label: 'System health' }] : []),
  ]);
  readonly tab = signal('users');
  readonly users = signal<AdminUser[]>([]);
  readonly roles = signal<Role[]>([]);
  readonly permissions = signal<Permission[]>([]);
  readonly flags = signal<FeatureFlag[]>([]);
  readonly health = signal<SystemHealth | null>(null);
  readonly q = signal('');
  readonly newUser = signal(false);
  readonly saved = signal(false);
  readonly error = signal<string | null>(null);
  u: NewUserForm = { ...EMPTY_USER };
  org: OrganisationForm = { name: '', currency: '', timezone: '', accent: DEFAULT_ACCENT };
  private timer?: ReturnType<typeof setInterval>;
  ago = ago;

  readonly components = computed(() => {
    const labels: Record<string, string> = {
      api: 'API',
      database: 'Metadata database',
      analytics_store: 'Analytical store',
      redis: 'Redis cache',
      ai_service: 'AI services',
      report_workers: 'Report workers',
      data_pipelines: 'Data pipelines',
      storage: 'Storage',
      kafka: 'Kafka',
    };
    return Object.entries(this.health()?.components ?? {}).map(([key, v]) => ({
      key,
      label: labels[key] ?? key,
      ...v,
    }));
  });

  ngOnInit() {
    this.go(this.tabs()[0]?.key ?? 'health');
  }
  ngOnDestroy() {
    clearInterval(this.timer);
  }

  async go(t: string) {
    this.tab.set(t);
    clearInterval(this.timer);
    try {
      if (t === 'users') {
        await this.loadUsers();
        this.roles.set((await this.api.get<Envelope<Role[]>>('/admin/roles')).data);
      }
      if (t === 'roles') {
        const [r, p] = await Promise.all([
          this.api.get<Envelope<Role[]>>('/admin/roles'),
          this.api.get<Envelope<Permission[]>>('/admin/permissions'),
        ]);
        this.roles.set(r.data);
        this.permissions.set(p.data);
      }
      if (t === 'org') {
        const o = (await this.api.get<Envelope<Organisation>>('/admin/organisation')).data;
        this.org = {
          name: o.name,
          currency: o.currency,
          timezone: o.timezone,
          accent: o.branding?.accent ?? DEFAULT_ACCENT,
        };
        await this.loadFlags();
      }
      if (t === 'health') {
        const tick = async () => this.health.set((await this.api.get<Envelope<SystemHealth>>('/admin/health')).data);
        await tick();
        this.timer = setInterval(tick, 15000);
      }
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
  async loadUsers() {
    this.users.set((await this.api.get<Envelope<AdminUser[]>>('/admin/users', { q: this.q() })).data);
  }
  async loadFlags() {
    this.flags.set((await this.api.get<Envelope<FeatureFlag[]>>('/admin/feature-flags')).data);
  }
  has(r: Role, key: string) {
    return r.permissions.some((p) => p.key === key || p.key === '*');
  }
  async setRole(x: AdminUser, role: string) {
    try {
      await this.api.patch(`/admin/users/${x.id}`, { roles: [role] });
      this.loadUsers();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
  async toggleStatus(x: AdminUser) {
    try {
      await this.api.patch(`/admin/users/${x.id}`, { status: x.status === 'active' ? 'suspended' : 'active' });
      this.loadUsers();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
  async createUser() {
    try {
      const scope = this.u.scope
        ? {
            country_codes: String(this.u.scope)
              .split(',')
              .map((s) => s.trim().toUpperCase())
              .filter(Boolean),
          }
        : {};
      await this.api.post('/admin/users', {
        name: this.u.name,
        email: this.u.email,
        title: this.u.title,
        password: this.u.password,
        roles: [this.u.role],
        attributes: scope,
      });
      this.u = { ...EMPTY_USER };
      this.newUser.set(false);
      this.loadUsers();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
  async saveOrg() {
    try {
      await this.api.patch('/admin/organisation', {
        name: this.org.name,
        currency: this.org.currency,
        timezone: this.org.timezone,
        branding: { accent: this.org.accent },
      });
      this.saved.set(true);
      this.auth.reloadProfile();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
  async setFlag(f: FeatureFlag, enabled: boolean) {
    try {
      await this.api.put(`/admin/feature-flags/${f.key}`, { enabled });
      await this.loadFlags();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
}
