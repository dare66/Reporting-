import { CdkTrapFocus } from '@angular/cdk/a11y';
import {
  ChangeDetectionStrategy,
  Component,
  OnInit,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../../core/api.service';
import { fmtDuration } from '../../../core/format';
import {
  CatalogModel,
  Envelope,
  Grain,
  HavingFilter,
  HavingOp,
  KpiCard,
  QueryFilter,
  QueryResult,
  QuickFunction,
  Widget,
} from '../../../core/models';
import { Chart } from '../../../shared/chart';
import { specFromGauge, specFromQuery } from '../../../shared/chart-spec';
import { Icon } from '../../../shared/icon';
import { Kpi } from '../../../shared/kpi';
import { PivotTable } from '../../../shared/pivot-table';
import { ResultTable } from '../../../shared/result-table';
import { Scrim } from '../../../shared/scrim';
import { FilterEditor, FilterTarget, MemberLoader } from '../filters/filter-editor';
import { describeFilter } from '../filters/filter-text';
import { DesignChange, DesignPanel } from './design-panel';
import {
  KINDS,
  QUICK_FUNCTIONS,
  StudioDraft,
  StudioKind,
  TIME,
  ValueField,
  WellKey,
  autoTitle,
  changeKind,
  emptyDraft,
  fromWidget,
  functionBlocker,
  kindDef,
  problems,
  toQuery,
  toViz,
  toWidget,
  valueKey,
} from './studio-model';

/** What the preview shows, by source. */
type Preview = { kind: 'query'; result: QueryResult } | { kind: 'kpi'; cards: KpiCard[] };

const RANGES = [
  { key: 'last_30_days', label: 'Last 30 days' },
  { key: 'last_90_days', label: 'Last 90 days' },
  { key: 'this_quarter', label: 'This quarter' },
  { key: 'last_6_months', label: 'Last 6 months' },
  { key: 'last_12_months', label: 'Last 12 months' },
  { key: 'last_24_months', label: 'Last 24 months' },
  { key: 'year_to_date', label: 'Year to date' },
  { key: 'last_year', label: 'Last year' },
];
const GRAINS: Grain[] = ['day', 'week', 'month', 'quarter', 'year'];
const HAVING_OPS: { op: HavingOp; label: string }[] = [
  { op: 'gt', label: '>' },
  { op: 'gte', label: '≥' },
  { op: 'lt', label: '<' },
  { op: 'lte', label: '≤' },
  { op: 'eq', label: '=' },
  { op: 'neq', label: '≠' },
  { op: 'between', label: 'between' },
  { op: 'not_between', label: 'not between' },
];

/**
 * Widget Studio: builds or edits a dashboard widget with a chart picker, a
 * data panel (wells, quick functions, filters), a design panel and a live,
 * governed preview. Design: docs/design/widget-studio.md §2.
 */
@Component({
  selector: 'app-widget-studio',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CdkTrapFocus, FormsModule, Scrim, Icon, Chart, Kpi, ResultTable, PivotTable, DesignPanel, FilterEditor],
  templateUrl: './widget-studio.html',
  styleUrl: './widget-studio.scss',
})
export class WidgetStudio implements OnInit {
  private api = inject(Api);
  readonly dashboardId = input.required<string>();
  readonly catalog = input.required<CatalogModel[]>();
  /** The widget to edit; null creates a new one. */
  readonly widget = input<Widget | null>(null);
  /** Grid row where a new widget is placed. */
  readonly nextRow = input(0);
  readonly saved = output<Widget>();
  readonly dismiss = output();

  readonly kinds = KINDS;
  readonly ranges = RANGES;
  readonly grains = GRAINS;
  readonly quickFunctions = QUICK_FUNCTIONS;
  readonly havingOps = HAVING_OPS;
  readonly TIME = TIME;
  readonly duration = fmtDuration;

  readonly draft = signal<StudioDraft>(emptyDraft('applications'));
  readonly notice = signal<string | null>(null);
  readonly openValue = signal<number | null>(null);
  readonly filterTarget = signal<{ target: FilterTarget; index: number | null } | null>(null);
  readonly preview = signal<Preview | null>(null);
  readonly previewError = signal<string | null>(null);
  readonly running = signal(false);
  readonly saving = signal(false);
  readonly saveError = signal<string | null>(null);
  private sequence = 0;

  readonly def = computed(() => kindDef(this.draft().kind));
  readonly model = computed(() => this.catalog().find((m) => m.key === this.draft().model));
  readonly dimensions = computed(() =>
    (this.model()?.dimensions ?? []).filter((d) => d.key !== this.model()?.time_dimension),
  );
  readonly timeLabel = computed(() => {
    const m = this.model();
    return m?.dimensions.find((d) => d.key === m.time_dimension)?.label ?? 'Time';
  });
  readonly issues = computed(() => problems(this.draft()));
  readonly query = computed(() => toQuery(this.draft()));
  readonly viz = computed(() => toViz(this.draft()));
  readonly title = computed(() => this.draft().title || autoTitle(this.draft(), this.model()));
  readonly valuesWell = computed(() => this.def().wells.find((w) => w.key === 'values'));
  /** Series the chart draws, for colour assignment; a break-by makes its members the series instead. */
  readonly series = computed(() =>
    this.draft().breakBy && this.draft().breakBy !== TIME
      ? []
      : this.draft().values.map((v) => ({ key: valueKey(v), label: this.valueLabel(v) })),
  );
  readonly additive = computed(() =>
    this.draft().values.every((v) => !v.fn && this.model()?.metrics.find((m) => m.key === v.metric)?.additive),
  );
  /** Changes only when the governed query (or KPI comparison) changes, so design tweaks never re-query. */
  private readonly previewKey = computed(() =>
    this.issues().length ? '' : JSON.stringify([this.draft().kind, this.query(), this.draft().viz.compare]),
  );

  readonly chartSpec = computed(() => {
    const p = this.preview();
    if (p?.kind !== 'query') return null;
    if (this.draft().kind === 'gauge') return specFromGauge(p.result, this.viz());
    return this.def().widgetType === 'chart' ? specFromQuery(p.result, this.viz()) : null;
  });
  readonly result = computed(() => {
    const p = this.preview();
    return p?.kind === 'query' ? p.result : null;
  });
  readonly cards = computed(() => {
    const p = this.preview();
    return p?.kind === 'kpi' ? p.cards : null;
  });

  constructor() {
    effect((onCleanup) => {
      const key = this.previewKey();
      if (!key) {
        this.preview.set(null);
        return;
      }
      const timer = setTimeout(() => this.runPreview(), 350);
      onCleanup(() => clearTimeout(timer));
    });
  }

  ngOnInit() {
    const w = this.widget();
    this.draft.set(w ? fromWidget(w) : emptyDraft(this.catalog()[0]?.key ?? 'applications'));
  }

  // ── Draft edits ──────────────────────────────────────────────────────────

  edit(change: Partial<StudioDraft>) {
    this.draft.update((d) => ({ ...d, ...change }));
  }
  readonly keyOf = valueKey;

  setKind(kind: StudioKind) {
    const { draft, dropped } = changeKind(this.draft(), kind);
    this.draft.set(draft);
    this.notice.set(
      dropped.length
        ? `Removed ${dropped.join(', ')} — not used by ${kindDef(kind).label.toLowerCase()} charts.`
        : null,
    );
  }

  setModel(model: string) {
    const kept = this.draft();
    this.draft.set({
      ...emptyDraft(model),
      kind: kept.kind,
      subtype: kept.subtype,
      title: kept.title,
      viz: kept.viz,
      range: kept.range,
    });
    this.notice.set('Fields and filters were cleared: they belong to the previous data model.');
  }

  setField(well: WellKey, key: string | null) {
    if (well === 'category')
      this.edit({ category: key, sort: this.draft().sort?.key === this.draft().category ? null : this.draft().sort });
    else if (well === 'breakBy') this.edit({ breakBy: key });
  }

  addValue(metric: string) {
    if (!metric) return;
    this.edit({ values: [...this.draft().values, { metric }] });
  }
  updateValue(i: number, change: Partial<ValueField>) {
    const before = this.draft().values[i];
    const values = this.draft().values.map((v, j) => (j === i ? { ...v, ...change } : v));
    // A sort follows its value when the value's key changes (e.g. a quick function is applied).
    const sort = this.draft().sort;
    this.edit({ values, sort: sort?.key === valueKey(before) ? { ...sort, key: valueKey(values[i]) } : sort });
  }
  removeValue(i: number) {
    const removed = valueKey(this.draft().values[i]);
    this.edit({
      values: this.draft().values.filter((_, j) => j !== i),
      sort: this.draft().sort?.key === removed ? null : this.draft().sort,
    });
    this.openValue.set(null);
  }
  setQuickFunction(i: number, fn: QuickFunction | '') {
    this.updateValue(
      i,
      fn ? { fn, window: fn === 'moving_average' ? 3 : undefined } : { fn: undefined, window: undefined },
    );
  }
  sortBy(key: string, dir: 'asc' | 'desc' | null) {
    this.edit({ sort: dir ? { key, dir } : null });
  }
  sortDir(key: string): 'asc' | 'desc' | null {
    const s = this.draft().sort;
    return s?.key === key ? s.dir : null;
  }

  design(change: DesignChange) {
    this.edit({
      ...(change.viz ? { viz: change.viz } : {}),
      ...(change.subtype ? { subtype: change.subtype } : {}),
      ...('limit' in change
        ? { limit: change.limit && change.limit > 0 ? Math.min(500, Math.round(change.limit)) : null }
        : {}),
    });
  }

  // ── Filters ──────────────────────────────────────────────────────────────

  readonly filterChips = computed(() =>
    this.draft().filters.map((f) => describeFilter(f, this.dimensionLabel(f.dimension), (k) => this.metricLabel(k))),
  );
  readonly certifiedMetrics = computed(() => (this.model()?.metrics ?? []).filter((m) => m.status === 'certified'));
  readonly otherMetrics = computed(() =>
    (this.model()?.metrics ?? []).filter((m) => m.status !== 'certified' && m.status !== 'deprecated'),
  );
  readonly rankMetrics = computed(() => (this.model()?.metrics ?? []).map((m) => ({ key: m.key, label: m.label })));

  startFilter(dimension: string, index: number | null = null) {
    const d = this.model()?.dimensions.find((x) => x.key === dimension);
    if (d) this.filterTarget.set({ target: { key: d.key, label: d.label, type: d.type }, index });
  }
  editFilter(i: number) {
    this.startFilter(this.draft().filters[i].dimension, i);
  }
  applyFilter(f: QueryFilter) {
    const t = this.filterTarget();
    const filters = [...this.draft().filters];
    if (t?.index == null) filters.push(f);
    else filters[t.index] = f;
    this.edit({ filters });
    this.filterTarget.set(null);
  }
  removeFilter(i: number) {
    this.edit({ filters: this.draft().filters.filter((_, j) => j !== i) });
  }
  readonly members =
    (dimension: string): MemberLoader =>
    async (search: string) =>
      (
        await this.api.get<Envelope<string[]>>(
          `/semantic-models/${this.draft().model}/dimensions/${dimension}/members`,
          search ? { search } : {},
        )
      ).data;

  addHaving(metric: string) {
    if (metric) this.edit({ having: [...this.draft().having, { metric, op: 'gt', value: 0 }] });
  }
  updateHaving(i: number, change: Partial<HavingFilter>) {
    this.edit({ having: this.draft().having.map((h, j) => (j === i ? normaliseHaving({ ...h, ...change }) : h)) });
  }
  removeHaving(i: number) {
    this.edit({ having: this.draft().having.filter((_, j) => j !== i) });
  }
  havingBound(h: HavingFilter, index: 0 | 1): number {
    return Array.isArray(h.value) ? h.value[index] : h.value;
  }
  setHavingBound(i: number, index: 0 | 1, raw: unknown) {
    const h = this.draft().having[i];
    const v = typeof raw === 'number' && Number.isFinite(raw) ? raw : 0;
    const pair: [number, number] = Array.isArray(h.value) ? [...h.value] : [h.value, h.value];
    pair[index] = v;
    this.updateHaving(i, { value: isRange(h.op) ? pair : v });
  }
  isRange = isRange;

  // ── Labels ───────────────────────────────────────────────────────────────

  metricLabel(key: string) {
    return this.model()?.metrics.find((m) => m.key === key)?.label ?? key.replace(/_/g, ' ');
  }
  dimensionLabel(key: string) {
    return key === TIME ? this.timeLabel() : (this.model()?.dimensions.find((d) => d.key === key)?.label ?? key);
  }
  valueLabel(v: ValueField) {
    const fn = QUICK_FUNCTIONS.find((q) => q.fn === v.fn)?.label;
    return fn
      ? `${this.metricLabel(v.metric)} · ${fn}${v.fn === 'moving_average' ? ` (${v.window ?? 3})` : ''}`
      : this.metricLabel(v.metric);
  }
  optionLabel(d: { label: string; accessible: boolean }) {
    return d.accessible ? d.label : `${d.label} — restricted`;
  }
  blocker(v: ValueField, fn: QuickFunction) {
    const m = this.model();
    return m ? functionBlocker(this.draft(), v.metric, fn, m) : null;
  }
  fieldOptions(well: WellKey) {
    const w = this.def().wells.find((x) => x.key === well);
    const other = well === 'category' ? this.draft().breakBy : this.draft().category;
    return {
      time: !!w && w.accepts !== 'dimension' && other !== TIME && !!this.model()?.time_dimension,
      dimensions: w && w.accepts !== 'time' ? this.dimensions().filter((d) => d.key !== other) : [],
    };
  }
  fieldOf(well: WellKey) {
    return well === 'category' ? this.draft().category : well === 'breakBy' ? this.draft().breakBy : null;
  }

  // ── Preview & save ───────────────────────────────────────────────────────

  private async runPreview() {
    const seq = ++this.sequence;
    const d = this.draft();
    this.running.set(true);
    this.previewError.set(null);
    try {
      const preview: Preview =
        d.kind === 'kpi'
          ? {
              kind: 'kpi',
              cards: (
                await this.api.post<Envelope<KpiCard[]>>('/kpis', {
                  metrics: [...new Set(d.values.map((v) => `${d.model}.${v.metric}`))],
                  range: d.range,
                  filters: d.filters,
                  compare: d.viz.compare ?? 'previous_period',
                })
              ).data,
            }
          : { kind: 'query', result: await this.api.post<QueryResult>('/query', this.query()) };
      if (seq === this.sequence) this.preview.set(preview);
    } catch (e) {
      if (seq === this.sequence) {
        this.preview.set(null);
        this.previewError.set(errorMessage(e));
      }
    } finally {
      if (seq === this.sequence) this.running.set(false);
    }
  }

  async save() {
    if (this.issues().length) return;
    this.saving.set(true);
    this.saveError.set(null);
    const body = { ...toWidget(this.draft()), title: this.title() };
    const existing = this.widget();
    try {
      const saved = existing
        ? (await this.api.patch<Envelope<Widget>>(`/dashboards/${this.dashboardId()}/widgets/${existing.id}`, body))
            .data
        : (
            await this.api.post<Envelope<Widget>>(`/dashboards/${this.dashboardId()}/widgets`, {
              ...body,
              position: { x: 0, y: this.nextRow(), w: 6, h: body.type === 'kpi' ? 2 : 5 },
            })
          ).data;
      this.saved.emit(saved);
    } catch (e) {
      this.saveError.set(errorMessage(e));
    } finally {
      this.saving.set(false);
    }
  }
}

const isRange = (op: HavingOp) => op === 'between' || op === 'not_between';

/** Keeps a measure filter's value shaped for its operator (a pair for ranges). */
function normaliseHaving(h: HavingFilter): HavingFilter {
  if (isRange(h.op)) return Array.isArray(h.value) ? h : { ...h, value: [h.value, h.value] };
  return Array.isArray(h.value) ? { ...h, value: h.value[0] } : h;
}
