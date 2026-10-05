import { Injectable, computed, signal } from '@angular/core';

export interface Project {
  id: string;
  key: string;
  name: string;
  description: string | null;
  visibility: 'organisation' | 'members';
  is_default: boolean;
  my_role: 'owner' | 'member' | null;
  can_manage: boolean;
  member_count: number;
  counts: Record<'data_sources' | 'datasets' | 'semantic_models' | 'dashboards' | 'reports' | 'alert_rules', number>;
  created_at: string | null;
  members?: { id: string; name: string; email: string; role: 'owner' | 'member' }[];
}

const KEY = 'aixbi.project.';

/**
 * Which project the person is working in. Every API and AI call carries it as
 * `X-Project-Id`; none means every project they may open (new work then goes
 * into the default project). Remembered per person on this device.
 */
@Injectable({ providedIn: 'root' })
export class ProjectScope {
  readonly projects = signal<Project[]>([]);
  readonly currentId = signal<string | null>(null);
  readonly current = computed(() => this.projects().find((p) => p.id === this.currentId()) ?? null);
  private userId: string | null = null;

  /** Restores the person's last project; call once their profile is known. */
  restore(userId: string) {
    this.userId = userId;
    try {
      this.currentId.set(localStorage.getItem(KEY + userId) || null);
    } catch {
      this.currentId.set(null);
    }
  }

  /** Keeps the list fresh, and forgets a remembered project the person can no longer open. */
  setProjects(list: Project[]) {
    this.projects.set(list);
    const id = this.currentId();
    if (id && !list.some((p) => p.id === id)) this.select(null);
  }

  select(id: string | null) {
    this.currentId.set(id);
    try {
      if (!this.userId) return;
      if (id) localStorage.setItem(KEY + this.userId, id);
      else localStorage.removeItem(KEY + this.userId);
    } catch {
      /* the choice still applies for this visit */
    }
  }

  /** Headers for calls made outside HttpClient (streaming fetches). */
  headers(): Record<string, string> {
    const id = this.currentId();
    return id ? { 'X-Project-Id': id } : {};
  }
}
