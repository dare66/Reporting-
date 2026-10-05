import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { ago, fmt } from '../../core/format';
import { ActionItem } from '../../core/models';
import { Icon } from '../icon';

const KIND: Record<string, { icon: string; label: string }> = {
  incident: { icon: 'warning', label: 'Open an incident' },
  notify: { icon: 'bell', label: 'Notify people' },
  webhook: { icon: 'link', label: 'Send to a system' },
  slack: { icon: 'send', label: 'Post to Slack' },
  teams: { icon: 'send', label: 'Post to Teams' },
  email: { icon: 'send', label: 'Send an email' },
};

const STATUS: Record<string, { label: string; tone: string }> = {
  proposed: { label: 'Awaiting approval', tone: 'warning' },
  approved: { label: 'Approved', tone: 'ai' },
  running: { label: 'Running', tone: 'ai' },
  done: { label: 'Done', tone: 'good' },
  failed: { label: 'Failed', tone: 'critical' },
  rejected: { label: 'Not approved', tone: '' },
  cancelled: { label: 'Withdrawn', tone: '' },
};

/**
 * One action from proposal to verified result, with the decisions the viewer
 * may take: approve, reject with a reason, withdraw their own, or run a failed
 * one again. Used in the AI analyst's answer and on the Actions page.
 */
@Component({
  selector: 'app-action-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, RouterLink, Icon],
  templateUrl: './action-card.html',
  styleUrl: './action-card.scss',
})
export class ActionCard implements OnInit {
  private api = inject(Api);
  /** Pass the action, or only its id to load it. */
  readonly action = input<ActionItem | null>(null);
  readonly actionId = input<string | null>(null);
  readonly highlight = input(false);
  readonly changed = output<ActionItem>();

  readonly loaded = signal<ActionItem | null>(null);
  readonly current = computed(() => this.loaded() ?? this.action());
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);
  readonly rejecting = signal(false);
  readonly note = signal('');
  readonly kind = computed(() => KIND[this.current()?.kind ?? ''] ?? { icon: 'flow', label: 'Action' });
  readonly status = computed(() => STATUS[this.current()?.status ?? ''] ?? { label: this.current()?.status, tone: '' });
  ago = ago;

  ngOnInit() {
    if (!this.action() && this.actionId()) this.reload();
  }

  async reload() {
    const id = this.current()?.id ?? this.actionId();
    if (!id) return;
    try {
      this.loaded.set((await this.api.get<{ data: ActionItem }>(`/actions/${id}`)).data);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  async decide(verb: 'approve' | 'reject' | 'cancel' | 'retry') {
    const a = this.current();
    if (!a) return;
    if (verb === 'reject' && !this.note().trim()) {
      this.error.set('Give a short reason, so the person who proposed it knows why.');
      return;
    }
    this.busy.set(true);
    this.error.set(null);
    try {
      const body = verb === 'approve' || verb === 'reject' ? { note: this.note().trim() || null } : {};
      const res = await this.api.post<{ data: ActionItem }>(`/actions/${a.id}/${verb}`, body);
      this.loaded.set(res.data);
      this.rejecting.set(false);
      this.note.set('');
      this.changed.emit(res.data);
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  value(a: ActionItem): string | null {
    const c = a.evidence?.card;
    return c?.value === undefined || c.value === null ? null : fmt(c.value, c.format ?? 'number');
  }

  share(n?: number | null): string {
    return n === undefined || n === null ? '' : ` · ${Math.round(n * 100)}% of the change`;
  }
}
