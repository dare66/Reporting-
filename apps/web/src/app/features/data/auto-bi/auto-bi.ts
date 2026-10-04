import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { DecimalPipe, LowerCasePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Api, QueryParams, errorMessage } from '../../../core/api.service';
import { Auth } from '../../../core/auth.service';
import { fmtDate } from '../../../core/format';
import {
  Audience,
  AutoBiDataset,
  AutoBiKpi,
  AutoBiPlan,
  AutoBiPublished,
  Envelope,
  FieldRole,
} from '../../../core/models';
import { Icon } from '../../../shared/icon';
import { ErrorState, Working } from '../../../shared/states';

type Step = 'understand' | 'kpis' | 'design';

/** How each detected role reads to a person. */
const ROLE_LABEL: Record<FieldRole, string> = {
  identifier: 'Identifier',
  time: 'Date',
  measure: 'Measure',
  dimension: 'Category',
  geography: 'Place',
  status: 'Status',
  flag: 'Yes / no',
  text: 'Free text',
  ignored: 'Not used',
};

/**
 * Auto BI Designer: shows what AIXBI understood about a source (with the
 * evidence and confidence behind each conclusion), lets a person approve the
 * KPIs, previews the dashboard and report it would build, then publishes them.
 */
@Component({
  selector: 'app-auto-bi',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, FormsModule, DecimalPipe, LowerCasePipe, Icon, Working, ErrorState],
  templateUrl: './auto-bi.html',
  styleUrl: './auto-bi.scss',
})
export class AutoBiPage implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly id = input.required<string>();

  readonly plan = signal<AutoBiPlan | null>(null);
  readonly error = signal<string | null>(null);
  readonly step = signal<Step>('understand');
  readonly audience = signal<Audience>('executive');
  readonly table = signal<string | null>(null);
  /** Approved KPI keys, in display order. */
  readonly approved = signal<string[]>([]);
  readonly labels = signal<Partial<Record<string, string>>>({});
  readonly refreshing = signal(false);
  readonly publishing = signal(false);
  readonly published = signal<AutoBiPublished | null>(null);
  readonly publishError = signal<string | null>(null);

  readonly roleLabel = ROLE_LABEL;
  readonly fmtDate = fmtDate;
  readonly canPublish = computed(() =>
    ['data.manage', 'semantic.manage', 'dashboards.manage', 'reports.manage'].every((p) => this.auth.can(p)),
  );
  readonly current = computed<AutoBiDataset | null>(() => {
    const p = this.plan();
    if (!p) return null;
    return p.datasets.find((d) => d.name === this.table()) ?? p.datasets.find((d) => d.is_fact) ?? null;
  });
  readonly rows = computed(() =>
    Math.max(1, ...(this.plan()?.dashboard.widgets.map((w) => w.position.y + w.position.h) ?? [1])),
  );

  ngOnInit() {
    this.load();
  }

  async load(keepSelection = false) {
    this.error.set(null);
    this.refreshing.set(true);
    try {
      const params: QueryParams = { audience: this.audience() };
      if (keepSelection && this.approved().length) {
        params['kpis'] = this.approved();
        params['labels'] = this.labels();
      }
      const p = (await this.api.get<Envelope<AutoBiPlan>>(`/data-sources/${this.id()}/auto-bi`, params)).data;
      this.plan.set(p);
      if (!keepSelection) this.approved.set(p.kpis.filter((k) => k.recommended).map((k) => k.key));
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.refreshing.set(false);
    }
  }

  isApproved(k: AutoBiKpi): boolean {
    return this.approved().includes(k.key);
  }

  toggle(k: AutoBiKpi) {
    this.approved.update((a) => (a.includes(k.key) ? a.filter((x) => x !== k.key) : [...a, k.key]));
  }

  rename(k: AutoBiKpi, label: string) {
    this.labels.update((l) => ({ ...l, [k.key]: label }));
  }

  /** The approved KPIs in the order they will appear, with any new names. */
  readonly chosen = computed(() => {
    const byKey = new Map((this.plan()?.kpis ?? []).map((k) => [k.key, k]));
    return this.approved()
      .map((key) => byKey.get(key))
      .filter((k): k is AutoBiKpi => !!k)
      .map((k) => ({ ...k, label: this.labels()[k.key]?.trim() || k.label }));
  });

  move(key: string, by: -1 | 1) {
    this.approved.update((a) => {
      const i = a.indexOf(key);
      const j = i + by;
      if (i < 0 || j < 0 || j >= a.length) return a;
      const next = [...a];
      [next[i], next[j]] = [next[j], next[i]];
      return next;
    });
  }

  async goDesign() {
    this.step.set('design');
    await this.load(true);
  }

  async setAudience(a: Audience) {
    this.audience.set(a);
    await this.load(true);
  }

  async publish() {
    this.publishing.set(true);
    this.publishError.set(null);
    try {
      const res = await this.api.post<Envelope<AutoBiPublished>>(`/data-sources/${this.id()}/auto-bi`, {
        kpis: this.approved(),
        audience: this.audience(),
        labels: this.labels(),
      });
      this.published.set(res.data);
    } catch (e) {
      this.publishError.set(errorMessage(e));
    } finally {
      this.publishing.set(false);
    }
  }

  pct(v: number | null | undefined, digits = 0): string {
    return v == null ? '—' : `${(v * 100).toFixed(digits)}%`;
  }

  tone(confidence: number): 'good' | 'warning' | 'critical' {
    return confidence >= 0.85 ? 'good' : confidence >= 0.7 ? 'warning' : 'critical';
  }

  short(ref: string): string {
    return ref.split('.')[1] ?? ref;
  }
}
