import { ChangeDetectionStrategy, Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../core/api.service';
import {
  Department,
  Envelope,
  FeatureFlag,
  Organisation,
  Permission,
  Role,
  SecurityPolicy,
  SystemHealth,
} from '../../core/models';
import { Auth } from '../../core/auth.service';
import { ago } from '../../core/format';
import { ACCENTS, Theme } from '../../core/theme.service';
import { ErrorState } from '../../shared/states';
import { RolesTab } from './roles/roles-tab';
import { SecurityTab } from './security/security-tab';
import { UsersTab } from './users/users-tab';

type Tab = 'users' | 'roles' | 'security' | 'org' | 'health';

const DEFAULT_ACCENT = '#e8b04b';

interface OrganisationForm {
  name: string;
  currency: string;
  timezone: string;
  accent: string;
}

@Component({
  selector: 'app-admin',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, ErrorState, UsersTab, RolesTab, SecurityTab],
  templateUrl: './admin.html',
  styleUrl: './admin.scss',
})
export class Admin implements OnInit, OnDestroy {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly theme = inject(Theme);
  readonly accents = ACCENTS;
  readonly tabs = computed<{ key: Tab; label: string }[]>(() => [
    ...(this.auth.can('admin.users')
      ? [
          { key: 'users' as const, label: 'People' },
          { key: 'roles' as const, label: 'Roles & permissions' },
        ]
      : []),
    ...(this.auth.can('admin.org')
      ? [
          { key: 'security' as const, label: 'Security policy' },
          { key: 'org' as const, label: 'Organisation' },
        ]
      : []),
    ...(this.auth.can('admin.system') ? [{ key: 'health' as const, label: 'System health' }] : []),
  ]);
  readonly tab = signal<Tab>('users');
  readonly roles = signal<Role[]>([]);
  readonly permissions = signal<Permission[]>([]);
  readonly departments = signal<Department[]>([]);
  readonly policy = signal<SecurityPolicy | null>(null);
  readonly flags = signal<FeatureFlag[]>([]);
  readonly health = signal<SystemHealth | null>(null);
  readonly saved = signal(false);
  readonly error = signal<string | null>(null);
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

  async go(t: Tab) {
    this.tab.set(t);
    clearInterval(this.timer);
    try {
      if (t === 'users' || t === 'roles') await this.loadDirectory();
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

  /** Roles, permissions, departments and the policy: shared by the People and Roles tabs. */
  async loadDirectory() {
    const [roles, permissions, departments] = await Promise.all([
      this.api.get<Envelope<Role[]>>('/admin/roles'),
      this.api.get<Envelope<Permission[]>>('/admin/permissions'),
      this.api.get<Envelope<Department[]>>('/admin/departments'),
    ]);
    this.roles.set(roles.data);
    this.permissions.set(permissions.data);
    this.departments.set(departments.data);
    if (this.auth.can('admin.org') && !this.policy()) {
      this.policy.set((await this.api.get<Envelope<SecurityPolicy>>('/admin/security-policy')).data);
    }
  }
  async reloadRoles() {
    this.roles.set((await this.api.get<Envelope<Role[]>>('/admin/roles')).data);
  }
  async loadFlags() {
    this.flags.set((await this.api.get<Envelope<FeatureFlag[]>>('/admin/feature-flags')).data);
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
  flagChanged(f: FeatureFlag, e: Event) {
    this.setFlag(f, (e.target as HTMLInputElement).checked);
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
