import { CdkTrapFocus } from '@angular/cdk/a11y';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ago, fmtDate } from '../../core/format';
import {
  Envelope,
  MetricAction,
  MetricDetail,
  MetricSnapshot,
  MetricVersionEntry,
  PersonRef,
  StoreMetric,
} from '../../core/models';
import { Icon } from '../../shared/icon';
import { Scrim } from '../../shared/scrim';
import { STATUS_LABEL } from './metric-store';

interface Draft {
  label: string;
  description: string;
  expression: string;
  format: string;
  target: string;
  higher_is_better: boolean;
  is_kpi: boolean;
  synonyms: string;
  business_owner_id: string;
  data_owner_id: string;
}

/** A field that differs between two versions, in words a reviewer reads. */
interface Change {
  field: string;
  before: string;
  after: string;
}

const FIELD_LABEL: Record<string, string> = {
  label: 'Name',
  description: 'Definition',
  expression: 'Calculation',
  measures: 'Measures used',
  format: 'Format',
  higher_is_better: 'Direction',
  target: 'Target',
  synonyms: 'Synonyms',
  is_kpi: 'KPI',
  owner: 'Owning team',
  business_owner_id: 'Business owner',
  data_owner_id: 'Data owner',
};

const HISTORY_LABEL: Partial<Record<string, string>> = {
  'metric.updated': 'Edited',
  'metric.approved': 'Approved',
  'metric.certified': 'Certified',
  'metric.revoked': 'Certification revoked',
  'metric.deprecated': 'Deprecated',
  'metric.reinstated': 'Reinstated',
  'metric.restored': 'Restored an earlier version',
};

@Component({
  selector: 'app-metric-drawer',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CdkTrapFocus, FormsModule, RouterLink, Scrim, Icon],
  templateUrl: './metric-drawer.html',
  styleUrl: './metric-drawer.scss',
})
export class MetricDrawer implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly metric = input.required<StoreMetric>();
  readonly siblings = input<StoreMetric[]>([]);
  readonly changed = output();
  readonly dismiss = output();

  readonly detail = signal<MetricDetail | null>(null);
  readonly people = signal<PersonRef[]>([]);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);
  readonly busy = signal(false);
  readonly editing = signal(false);
  readonly pending = signal<'revoke' | 'deprecate' | null>(null);
  readonly compare = signal<number | null>(null);
  draft: Draft | null = null;
  note = '';
  replacement = '';

  readonly statusLabel = STATUS_LABEL;
  readonly historyLabel = HISTORY_LABEL;
  readonly ago = ago;
  readonly fmtDate = fmtDate;
  readonly steps = ['proposed', 'approved', 'certified'] as const;

  /** How far along the lifecycle the metric is (deprecated sits outside it). */
  readonly reached = computed(() => {
    const s = this.detail()?.status;
    return s === 'certified' ? 2 : s === 'approved' ? 1 : s === 'proposed' ? 0 : -1;
  });

  async ngOnInit() {
    await this.load();
    if (this.auth.can('semantic.manage')) {
      this.people.set((await this.api.get<Envelope<PersonRef[]>>('/metric-store-people')).data);
    }
  }

  private url(): string {
    return `/metric-store/${this.metric().model.key}/${this.metric().key}`;
  }

  async load() {
    try {
      this.detail.set((await this.api.get<Envelope<MetricDetail>>(this.url())).data);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  /** Runs a change, shows its outcome, and tells the list to refresh. */
  private async run(done: string, call: () => Promise<Envelope<MetricDetail>>) {
    this.busy.set(true);
    this.error.set(null);
    this.notice.set(null);
    try {
      this.detail.set((await call()).data);
      this.notice.set(done);
      this.changed.emit();
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  act(action: MetricAction) {
    const body: Record<string, string | null> = { action };
    if (action === 'revoke' || action === 'deprecate') body['note'] = this.note;
    if (action === 'deprecate') body['replaced_by'] = this.replacement || null;
    const done: Record<MetricAction, string> = {
      approve: 'Approved. A second person can now certify it.',
      certify: 'Certified.',
      revoke: 'Certification revoked.',
      deprecate: 'Deprecated. Existing widgets keep working; it is no longer offered for new work.',
      reinstate: 'Reinstated as proposed.',
    };
    return this.run(done[action], async () => {
      const res = await this.api.post<Envelope<MetricDetail>>(`${this.url()}/transitions`, body);
      this.pending.set(null);
      this.note = '';
      return res;
    });
  }

  startEdit(d: MetricDetail) {
    this.draft = {
      label: d.label,
      description: d.description ?? '',
      expression: d.expression,
      format: d.format,
      target: d.target === null ? '' : String(d.target),
      higher_is_better: d.higher_is_better,
      is_kpi: d.is_kpi,
      synonyms: d.synonyms.join(', '),
      business_owner_id: d.business_owner?.id ?? '',
      data_owner_id: d.data_owner?.id ?? '',
    };
    this.editing.set(true);
  }

  save() {
    const d = this.draft;
    if (!d) return;
    return this.run('Saved as a new version.', async () => {
      const res = await this.api.patch<Envelope<MetricDetail>>(this.url(), {
        label: d.label.trim(),
        description: d.description.trim() || null,
        expression: d.expression.trim(),
        format: d.format,
        target: d.target.trim() === '' ? null : Number(d.target),
        higher_is_better: d.higher_is_better,
        is_kpi: d.is_kpi,
        synonyms: d.synonyms
          .split(',')
          .map((s) => s.trim())
          .filter(Boolean),
        business_owner_id: d.business_owner_id || null,
        data_owner_id: d.data_owner_id || null,
      });
      this.editing.set(false);
      return res;
    });
  }

  restore(version: number) {
    return this.run(`Version ${version} restored as a new version.`, () =>
      this.api.post<Envelope<MetricDetail>>(`${this.url()}/versions/${version}/restore`),
    );
  }

  /** What changed in a version compared with the one before it. */
  changes(versions: MetricVersionEntry[], index: number): Change[] {
    const after = versions[index]?.definition;
    const before = versions[index + 1]?.definition;
    if (!after || !before) return [];
    return (Object.keys(FIELD_LABEL) as (keyof MetricSnapshot)[])
      .filter((k) => JSON.stringify(after[k] ?? null) !== JSON.stringify(before[k] ?? null))
      .map((k) => ({ field: FIELD_LABEL[k], before: this.show(k, before), after: this.show(k, after) }));
  }

  private show(field: keyof MetricSnapshot, s: MetricSnapshot): string {
    const v = s[field];
    if (field === 'business_owner_id' || field === 'data_owner_id')
      return this.people().find((p) => p.id === v)?.name ?? (v ? 'someone' : '—');
    if (field === 'higher_is_better') return v ? 'Higher is better' : 'Lower is better';
    if (field === 'measures')
      return s.measures
        .map((m) => {
          const where = m.filters.map((f) => `${f.field} ${f.op} ${JSON.stringify(f.value ?? null)}`).join(' and ');
          return `${m.key}: ${m.aggregation}(${m.field ?? '*'})${where ? ' where ' + where : ''}`;
        })
        .join('; ');
    if (Array.isArray(v)) return v.join(', ') || '—';
    return v === null || v === undefined || v === '' ? '—' : String(v);
  }

  personName(id: string | null | undefined): string {
    return this.people().find((p) => p.id === id)?.name ?? '';
  }
}
