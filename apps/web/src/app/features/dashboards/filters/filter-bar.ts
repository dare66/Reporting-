import { ChangeDetectionStrategy, Component, computed, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Dashboard, DashboardFilter, FilterDimension, QueryFilter } from '../../../core/models';
import { Icon } from '../../../shared/icon';
import { FilterEditor, MemberLoader } from './filter-editor';
import { describeFilter } from './filter-text';

/** What the drop panel is doing: picking a dimension for a new filter, or editing one. */
type Panel = { mode: 'pick' } | { mode: 'edit'; index: number | null; dimension: FilterDimension };

/**
 * Dashboard filter bar: one chip per filter in plain language, an inline editor
 * (members, text, numeric, ranking), pause, reset and — for editors — save as default.
 * Filters are ANDed and reach only widgets whose model has the dimension.
 */
@Component({
  selector: 'app-filter-bar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, FilterEditor],
  templateUrl: './filter-bar.html',
  styleUrl: './filter-bar.scss',
})
export class FilterBar {
  readonly filters = input.required<DashboardFilter[]>();
  readonly dimensions = input.required<FilterDimension[]>();
  readonly metrics = input<Dashboard['filter_metrics']>([]);
  readonly memberLoader = input.required<(dimension: string) => MemberLoader>();
  readonly canSave = input(false);
  /** The current filters differ from the dashboard's saved defaults. */
  readonly dirty = input(false);
  readonly filtersChange = output<DashboardFilter[]>();
  readonly saveDefault = output();
  readonly resetFilters = output();

  readonly panel = signal<Panel | null>(null);
  readonly query = signal('');
  readonly paused = signal(false);

  readonly chips = computed(() =>
    this.filters().map((f, index) => ({
      index,
      filter: f,
      text: describeFilter(f, this.dimension(f.dimension)?.label ?? f.label ?? f.dimension, (k) => this.metricLabel(k)),
    })),
  );
  readonly choices = computed(() => {
    const q = this.query().trim().toLowerCase();
    return this.dimensions().filter((d) => !q || d.label.toLowerCase().includes(q) || d.key.includes(q));
  });
  /** Ranking metrics that live in a model with the dimension being edited. */
  readonly rankMetrics = computed(() => {
    const p = this.panel();
    if (p?.mode !== 'edit') return [];
    return this.metrics().filter((m) => m.models.some((x) => p.dimension.models.includes(x)));
  });
  readonly editing = computed(() => {
    const p = this.panel();
    return p?.mode === 'edit' && p.index !== null ? this.filters()[p.index] : null;
  });
  readonly totalModels = computed(() => new Set(this.dimensions().flatMap((d) => d.models)).size);

  private dimension(key: string) {
    return this.dimensions().find((d) => d.key === key);
  }
  private metricLabel(key: string) {
    return this.metrics().find((m) => m.key === key)?.label ?? key.replace(/_/g, ' ');
  }

  add() {
    this.query.set('');
    this.panel.set(this.panel()?.mode === 'pick' ? null : { mode: 'pick' });
  }
  pick(dimension: FilterDimension) {
    this.paused.set(false);
    this.panel.set({ mode: 'edit', index: null, dimension });
  }
  edit(index: number) {
    const f = this.filters()[index];
    const dimension = this.dimension(f.dimension) ?? {
      key: f.dimension,
      label: f.label ?? f.dimension,
      type: 'string',
      models: [],
    };
    this.paused.set(!!f.disabled);
    this.panel.set({ mode: 'edit', index, dimension });
  }
  close() {
    this.panel.set(null);
  }

  applied(filter: QueryFilter) {
    const p = this.panel();
    if (p?.mode !== 'edit') return;
    const next: DashboardFilter = { ...filter, label: p.dimension.label, ...(this.paused() ? { disabled: true } : {}) };
    const all = [...this.filters()];
    if (p.index === null) all.push(next);
    else all[p.index] = next;
    this.filtersChange.emit(all);
    this.close();
  }
  remove(index: number) {
    this.filtersChange.emit(this.filters().filter((_, i) => i !== index));
    if (this.panel()?.mode === 'edit') this.close();
  }
  togglePause(index: number) {
    this.filtersChange.emit(
      this.filters().map((f, i) => {
        if (i !== index) return f;
        const { disabled, ...rest } = f;
        return disabled ? rest : { ...rest, disabled: true };
      }),
    );
  }
}
