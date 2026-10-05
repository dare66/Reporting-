import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { Project, ProjectScope } from '../../core/project-scope.service';
import { Icon } from '../../shared/icon';
import { ErrorState } from '../../shared/states';

interface Person {
  id: string;
  name: string;
  title: string | null;
}

const CONTENT_LABELS: [keyof Project['counts'], string][] = [
  ['data_sources', 'sources'],
  ['semantic_models', 'models'],
  ['dashboards', 'dashboards'],
  ['reports', 'reports'],
  ['alert_rules', 'alerts'],
];

/**
 * Projects keep each team's or programme's data sources, models, dashboards
 * and reports apart. Owners and administrators manage details and members.
 */
@Component({
  selector: 'app-projects',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, ErrorState],
  templateUrl: './projects.html',
  styleUrl: './projects.scss',
})
export class Projects implements OnInit {
  readonly auth = inject(Auth);
  readonly scope = inject(ProjectScope);
  private api = inject(Api);

  readonly projects = this.scope.projects;
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);
  readonly busy = signal(false);
  readonly labels = CONTENT_LABELS;

  // Create
  readonly creating = signal(false);
  readonly name = signal('');
  readonly description = signal('');
  readonly visibility = signal<'members' | 'organisation'>('members');

  // Selected project
  readonly openId = signal<string | null>(null);
  readonly detail = signal<Project | null>(null);
  readonly people = signal<Person[]>([]);
  readonly addId = signal('');
  readonly addRole = signal<'member' | 'owner'>('member');
  readonly editName = signal('');
  readonly editDescription = signal('');
  readonly editVisibility = signal<'members' | 'organisation'>('members');
  readonly owners = computed(() => (this.detail()?.members ?? []).filter((m) => m.role === 'owner').length);

  ngOnInit() {
    this.load();
  }

  async load() {
    try {
      this.scope.setProjects((await this.api.get<{ data: Project[] }>('/projects')).data);
      this.error.set(null);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  async create() {
    if (!this.name().trim()) return;
    this.busy.set(true);
    try {
      const res = await this.api.post<{ data: Project }>('/projects', {
        name: this.name().trim(),
        description: this.description().trim() || null,
        visibility: this.visibility(),
      });
      this.name.set('');
      this.description.set('');
      this.creating.set(false);
      await this.load();
      this.notice.set(`${res.data.name} is ready. Switch to it from the top bar to add data, dashboards and reports.`);
      this.open(res.data);
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  async open(p: Project) {
    if (this.openId() === p.id) {
      this.openId.set(null);
      return;
    }
    this.openId.set(p.id);
    this.detail.set(null);
    try {
      const d = (await this.api.get<{ data: Project }>(`/projects/${p.id}`)).data;
      this.setDetail(d);
      this.people.set(d.can_manage ? (await this.api.get<{ data: Person[] }>(`/projects/${p.id}/people`)).data : []);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  private setDetail(d: Project) {
    this.detail.set(d);
    this.editName.set(d.name);
    this.editDescription.set(d.description ?? '');
    this.editVisibility.set(d.visibility);
  }

  async save() {
    const d = this.detail();
    if (!d) return;
    await this.run(async () => {
      await this.api.patch(`/projects/${d.id}`, {
        name: this.editName().trim(),
        description: this.editDescription().trim() || null,
        visibility: this.editVisibility(),
      });
      this.notice.set('Saved.');
    });
  }

  async addMember() {
    const d = this.detail();
    if (!d || !this.addId()) return;
    await this.run(async () => {
      await this.api.post(`/projects/${d.id}/members`, { user_id: this.addId(), role: this.addRole() });
      this.addId.set('');
    });
  }

  async setRole(userId: string, role: 'owner' | 'member') {
    const d = this.detail();
    if (!d) return;
    await this.run(() => this.api.post(`/projects/${d.id}/members`, { user_id: userId, role }));
  }

  async removeMember(userId: string) {
    const d = this.detail();
    if (!d) return;
    await this.run(() => this.api.delete(`/projects/${d.id}/members/${userId}`));
  }

  async remove() {
    const d = this.detail();
    if (!d || !confirm(`Delete the project “${d.name}”? It is empty, so nothing else is removed.`)) return;
    await this.run(async () => {
      await this.api.delete(`/projects/${d.id}`);
      if (this.scope.currentId() === d.id) this.scope.select(null);
      this.openId.set(null);
      this.notice.set(`${d.name} was deleted.`);
    }, false);
  }

  /** Runs a change, then refreshes the list and, unless it was removed, the open project. */
  private async run(change: () => Promise<unknown>, reopen = true) {
    const d = this.detail();
    this.busy.set(true);
    this.error.set(null);
    try {
      await change();
      await this.load();
      if (reopen && d) {
        this.setDetail((await this.api.get<{ data: Project }>(`/projects/${d.id}`)).data);
        this.people.set((await this.api.get<{ data: Person[] }>(`/projects/${d.id}/people`)).data);
      }
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  workIn(p: Project) {
    this.scope.select(p.id);
    this.notice.set(`You are now working in ${p.name}.`);
  }

  summary(p: Project): string {
    const parts = this.labels.filter(([k]) => p.counts[k] > 0).map(([k, l]) => `${p.counts[k]} ${l}`);
    return parts.length ? parts.join(' · ') : 'Empty';
  }
}
