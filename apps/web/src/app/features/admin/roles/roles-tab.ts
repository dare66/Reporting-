import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../../core/api.service';
import { Auth } from '../../../core/auth.service';
import { Envelope, Experience, Permission, Role } from '../../../core/models';
import { Icon } from '../../../shared/icon';

interface RoleDraft {
  id: string | null;
  key: string;
  name: string;
  description: string;
  experience: Experience;
  permissions: Set<string>;
}

const EXPERIENCES: { key: Experience; label: string; hint: string }[] = [
  { key: 'executive', label: 'Executive', hint: 'Focused surface: answers, dashboards, reports' },
  { key: 'analyst', label: 'Analyst', hint: 'Exploration and building' },
  { key: 'engineer', label: 'Engineer', hint: 'Data platform and semantic layer' },
  { key: 'admin', label: 'Administrator', hint: 'Organisation administration' },
];

/** Roles and their permissions. Platform roles are fixed; custom roles are edited here. */
@Component({
  selector: 'app-roles-tab',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon],
  templateUrl: './roles-tab.html',
  styleUrl: './roles-tab.scss',
})
export class RolesTab {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly roles = input.required<Role[]>();
  readonly permissions = input.required<Permission[]>();
  readonly rolesChange = output();

  readonly experiences = EXPERIENCES;
  readonly selectedId = signal<string | null>(null);
  readonly draft = signal<RoleDraft | null>(null);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);
  readonly saving = signal(false);
  readonly confirmDelete = signal(false);

  readonly canEdit = computed(() => this.auth.can('admin.roles'));
  readonly selected = computed(() => this.roles().find((r) => r.id === this.selectedId()) ?? null);
  readonly editable = computed(() => this.canEdit() && (this.draft()?.id === null || !this.selected()?.is_system));
  readonly groups = computed(() => {
    const by = new Map<string, Permission[]>();
    for (const p of this.permissions()) by.set(p.group, [...(by.get(p.group) ?? []), p]);
    return [...by.entries()].map(([group, items]) => ({ group, items }));
  });

  select(role: Role) {
    this.selectedId.set(role.id);
    this.confirmDelete.set(false);
    this.notice.set(null);
    this.error.set(null);
    this.draft.set({
      id: role.id,
      key: role.key,
      name: role.name,
      description: role.description ?? '',
      experience: role.experience,
      permissions: new Set(role.permissions.map((p) => p.key)),
    });
  }

  /** Starts a new custom role, optionally from an existing role's permissions. */
  startNew(from: Role | null = null) {
    this.selectedId.set(null);
    this.notice.set(null);
    this.draft.set({
      id: null,
      key: '',
      name: from ? `${from.name} (custom)` : '',
      description: from?.description ?? '',
      experience: from?.experience ?? 'analyst',
      permissions: new Set((from?.permissions ?? []).map((p) => p.key).filter((k) => k !== '*')),
    });
  }

  edit(change: Partial<RoleDraft>) {
    this.draft.update((d) => (d ? { ...d, ...change } : d));
  }
  has(key: string) {
    const d = this.draft();
    return !!d && (d.permissions.has(key) || d.permissions.has('*'));
  }
  toggle(key: string) {
    const d = this.draft();
    if (!d) return;
    const next = new Set(d.permissions);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    this.edit({ permissions: next });
  }
  toggleGroup(items: Permission[], on: boolean) {
    const d = this.draft();
    if (!d) return;
    const next = new Set(d.permissions);
    for (const p of items) {
      if (on) next.add(p.key);
      else next.delete(p.key);
    }
    this.edit({ permissions: next });
  }
  groupState(items: Permission[]): 'all' | 'some' | 'none' {
    const n = items.filter((p) => this.has(p.key)).length;
    return n === items.length ? 'all' : n ? 'some' : 'none';
  }

  /** A stable key from the name for new roles (lowercase, underscores). */
  keyFor(name: string) {
    return name
      .toLowerCase()
      .replace(/[^a-z]+/g, '_')
      .replace(/^_+|_+$/g, '')
      .slice(0, 60);
  }

  async save() {
    const d = this.draft();
    if (!d) return;
    this.saving.set(true);
    this.error.set(null);
    const body = {
      name: d.name.trim(),
      description: d.description.trim() || null,
      experience: d.experience,
      permissions: [...d.permissions],
    };
    try {
      const role = d.id
        ? (await this.api.patch<Envelope<Role>>(`/admin/roles/${d.id}`, body)).data
        : (await this.api.post<Envelope<Role>>('/admin/roles', { ...body, key: this.keyFor(d.name) })).data;
      this.notice.set(d.id ? 'Role saved. People in it get the change on their next request.' : 'Role created.');
      this.rolesChange.emit();
      this.selectedId.set(role.id);
      this.draft.update((x) => (x ? { ...x, id: role.id, key: role.key } : x));
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.saving.set(false);
    }
  }

  async remove() {
    const d = this.draft();
    if (!d?.id) return;
    if (!this.confirmDelete()) {
      this.confirmDelete.set(true);
      return;
    }
    try {
      await this.api.delete(`/admin/roles/${d.id}`);
      this.draft.set(null);
      this.selectedId.set(null);
      this.notice.set('Role deleted.');
      this.rolesChange.emit();
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.confirmDelete.set(false);
    }
  }
}
