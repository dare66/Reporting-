import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ago } from '../../core/format';
import { ActionDestination, ActionItem, ActionKind, Incident, IncidentSeverity } from '../../core/models';
import { ActionCard } from '../../shared/action-card/action-card';
import { Icon } from '../../shared/icon';
import { ErrorState } from '../../shared/states';

type Tab = 'awaiting' | 'all' | 'incidents' | 'destinations';

interface Person {
  id: string;
  name: string;
  title: string | null;
}

/**
 * The action engine's home: proposals waiting for approval, everything that
 * was proposed and what happened, the incidents approved actions opened, and
 * (for administrators) where actions may be sent.
 */
@Component({
  selector: 'app-actions',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, ErrorState, ActionCard],
  templateUrl: './actions.html',
  styleUrl: './actions.scss',
})
export class Actions implements OnInit {
  readonly auth = inject(Auth);
  private api = inject(Api);
  private route = inject(ActivatedRoute);

  readonly tab = signal<Tab>('awaiting');
  readonly actions = signal<ActionItem[]>([]);
  readonly awaiting = signal(0);
  readonly incidents = signal<Incident[]>([]);
  readonly destinations = signal<ActionDestination[]>([]);
  readonly people = signal<Person[]>([]);
  readonly error = signal<string | null>(null);
  readonly focus = signal<string | null>(null);
  readonly focusIncident = signal<string | null>(null);
  readonly canApprove = computed(() => this.auth.can('actions.approve'));
  readonly shown = computed(() =>
    this.tab() === 'awaiting' ? this.actions().filter((a) => a.status === 'proposed') : this.actions(),
  );
  ago = ago;

  // Propose
  readonly proposing = signal(false);
  readonly kind = signal<ActionKind>('incident');
  readonly title = signal('');
  readonly summary = signal('');
  readonly severity = signal<IncidentSeverity>('medium');
  readonly assignee = signal('');
  readonly destinationId = signal('');
  readonly busy = signal(false);

  // Destinations
  readonly dName = signal('');
  readonly dKind = signal<ActionDestination['kind']>('webhook');
  readonly dTarget = signal('');
  readonly dHeader = signal('');
  readonly dValue = signal('');

  ngOnInit() {
    const q = this.route.snapshot.queryParamMap;
    if (q.get('id')) {
      this.focus.set(q.get('id'));
      this.tab.set('all');
    }
    if (q.get('incident')) {
      this.focusIncident.set(q.get('incident'));
      this.tab.set('incidents');
    }
    this.load();
  }

  async load() {
    try {
      const [a, i, d] = await Promise.all([
        this.api.get<{ data: ActionItem[]; awaiting: number }>('/actions'),
        this.api.get<{ data: Incident[] }>('/incidents'),
        this.api.get<{ data: ActionDestination[] }>('/action-destinations'),
      ]);
      this.actions.set(a.data);
      this.awaiting.set(a.awaiting);
      this.incidents.set(i.data);
      this.destinations.set(d.data);
      if (
        !this.focus() &&
        !this.focusIncident() &&
        this.tab() === 'awaiting' &&
        !a.data.some((x) => x.status === 'proposed')
      )
        this.tab.set('all');
      this.error.set(null);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  async startProposal() {
    this.proposing.set(true);
    if (!this.people().length) {
      try {
        this.people.set((await this.api.get<{ data: Person[] }>('/action-people')).data);
      } catch {
        /* the assignee stays optional */
      }
    }
  }

  readonly needsDestination = computed(() => ['webhook', 'slack', 'teams', 'email'].includes(this.kind()));
  readonly destinationsForKind = computed(() =>
    this.destinations().filter((d) => d.kind === this.kind() && d.is_active),
  );

  async propose() {
    this.busy.set(true);
    this.error.set(null);
    try {
      const payload =
        this.kind() === 'incident' ? { severity: this.severity(), assignee_id: this.assignee() || null } : {};
      const res = await this.api.post<{ data: ActionItem }>('/actions', {
        kind: this.kind(),
        title: this.title().trim(),
        summary: this.summary().trim() || null,
        payload,
        destination_id: this.needsDestination() ? this.destinationId() : null,
      });
      this.proposing.set(false);
      this.title.set('');
      this.summary.set('');
      this.focus.set(res.data.id);
      this.tab.set('all');
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  async setIncident(i: Incident, status: Incident['status']) {
    try {
      await this.api.patch(`/incidents/${i.id}`, { status });
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  canWork(i: Incident): boolean {
    return this.canApprove() || i.assignee_id === this.auth.user()?.id;
  }

  async addDestination() {
    const kind = this.dKind();
    const config =
      kind === 'email'
        ? {
            recipients: this.dTarget()
              .split(/[,;\s]+/)
              .filter(Boolean),
          }
        : {
            url: this.dTarget().trim(),
            ...(this.dHeader().trim() ? { headers: { [this.dHeader().trim()]: this.dValue() } } : {}),
          };
    this.busy.set(true);
    this.error.set(null);
    try {
      await this.api.post('/action-destinations', { name: this.dName().trim(), kind, config });
      this.dName.set('');
      this.dTarget.set('');
      this.dHeader.set('');
      this.dValue.set('');
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  async toggleDestination(d: ActionDestination) {
    try {
      await this.api.patch(`/action-destinations/${d.id}`, { is_active: !d.is_active });
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  async removeDestination(d: ActionDestination) {
    if (!confirm(`Remove the destination “${d.name}”?`)) return;
    try {
      await this.api.delete(`/action-destinations/${d.id}`);
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  onChanged() {
    this.load();
  }
}
