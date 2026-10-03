import { ChangeDetectionStrategy, Component, OnInit, computed, effect, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { errorMessage } from '../../../core/api.service';
import { FilterOp, QueryFilter } from '../../../core/models';
import { FilterFamily, NUMERIC_OPS, TEXT_OPS, familyOf, isNumericType, isRanking } from './filter-text';

/** The dimension being filtered. */
export interface FilterTarget {
  key: string;
  label: string;
  type: string;
}

/** Loads the members a user may pick, optionally narrowed by a search term. */
export type MemberLoader = (search: string) => Promise<string[]>;

/**
 * Builds one dimension filter: members (include / exclude), text, numeric or
 * ranking (top / bottom N by a metric). Used by the dashboard filter bar and by
 * Widget Studio's widget filters.
 */
@Component({
  selector: 'app-filter-editor',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule],
  templateUrl: './filter-editor.html',
  styleUrl: './filter-editor.scss',
})
export class FilterEditor implements OnInit {
  readonly target = input.required<FilterTarget>();
  readonly initial = input<QueryFilter | null>(null);
  readonly metrics = input<{ key: string; label: string }[]>([]);
  readonly loadMembers = input.required<MemberLoader>();
  readonly apply = output<QueryFilter>();
  readonly dismiss = output();

  readonly textOps = TEXT_OPS;
  readonly numericOps = NUMERIC_OPS;
  readonly family = signal<FilterFamily>('members');
  readonly families = computed(() => {
    const all: { key: FilterFamily; label: string }[] = [
      { key: 'members', label: 'Members' },
      { key: 'text', label: 'Text' },
    ];
    if (isNumericType(this.target().type)) all.push({ key: 'numeric', label: 'Numeric' });
    if (this.metrics().length) all.push({ key: 'ranking', label: 'Ranking' });
    return all;
  });

  // Members
  readonly exclude = signal(false);
  readonly search = signal('');
  readonly members = signal<string[]>([]);
  readonly selected = signal<ReadonlySet<string>>(new Set());
  readonly loading = signal(false);
  readonly loadError = signal<string | null>(null);
  // Text / numeric
  readonly textOp = signal<FilterOp>('contains');
  readonly text = signal('');
  readonly numericOp = signal<FilterOp>('between');
  readonly from = signal<number | null>(null);
  readonly to = signal<number | null>(null);
  // Ranking
  readonly direction = signal<'top' | 'bottom'>('top');
  readonly n = signal(10);
  readonly metric = signal('');

  readonly twoValues = computed(() => this.numericOp() === 'between' || this.numericOp() === 'not_between');
  readonly result = computed<QueryFilter | null>(() => {
    const dimension = this.target().key;
    switch (this.family()) {
      case 'members': {
        const value = [...this.selected()];
        return value.length ? { dimension, op: this.exclude() ? 'not_in' : 'in', value } : null;
      }
      case 'text':
        return this.text().trim() ? { dimension, op: this.textOp(), value: this.text().trim() } : null;
      case 'numeric': {
        const [a, b] = [this.from(), this.to()];
        if (a === null || (this.twoValues() && b === null)) return null;
        return { dimension, op: this.numericOp(), value: this.twoValues() ? [a, b] : a };
      }
      case 'ranking':
        return this.metric() && this.n() >= 1
          ? {
              dimension,
              op: this.direction(),
              value: { n: Math.min(1000, Math.round(this.n())), metric: this.metric() },
            }
          : null;
    }
  });

  private searchTimer?: ReturnType<typeof setTimeout>;

  constructor() {
    // Re-query members as the search changes, debounced so typing stays smooth.
    effect((onCleanup) => {
      const term = this.search();
      if (this.family() !== 'members') return;
      this.searchTimer = setTimeout(() => this.fetchMembers(term), 250);
      onCleanup(() => clearTimeout(this.searchTimer));
    });
  }

  ngOnInit() {
    this.metric.set(this.metrics()[0]?.key ?? '');
    const f = this.initial();
    if (!f) return;
    this.family.set(familyOf(f, this.target().type));
    const v = f.value;
    if (f.op === 'in' || f.op === 'not_in') {
      this.exclude.set(f.op === 'not_in');
      this.selected.set(new Set((Array.isArray(v) ? v : []).map(String)));
    } else if ((f.op === 'top' || f.op === 'bottom') && isRanking(v)) {
      this.direction.set(f.op);
      this.n.set(v.n);
      this.metric.set(v.metric);
    } else if (this.family() === 'numeric') {
      this.numericOp.set(f.op);
      const [a, b] = Array.isArray(v) ? v : [v, null];
      this.from.set(typeof a === 'number' ? a : null);
      this.to.set(typeof b === 'number' ? b : null);
    } else {
      this.textOp.set(f.op);
      this.text.set(String(v ?? ''));
    }
  }

  private async fetchMembers(term: string) {
    this.loading.set(true);
    this.loadError.set(null);
    try {
      this.members.set(await this.loadMembers()(term));
    } catch (e) {
      this.loadError.set(errorMessage(e));
    } finally {
      this.loading.set(false);
    }
  }

  toggle(member: string) {
    this.selected.update((s) => {
      const next = new Set(s);
      if (next.has(member)) next.delete(member);
      else next.add(member);
      return next;
    });
  }
  selectShown() {
    this.selected.update((s) => new Set([...s, ...this.members()]));
  }
  clear() {
    this.selected.set(new Set());
  }
  /** Selected members that the current search hides, so a selection is never lost from view. */
  readonly hiddenSelected = computed(() => [...this.selected()].filter((m) => !this.members().includes(m)));

  submit() {
    const f = this.result();
    if (f) this.apply.emit(f);
  }
}
