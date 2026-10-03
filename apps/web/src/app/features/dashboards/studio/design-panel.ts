import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ChartSubtype, ColorRule, NumberFormat, RuleStatus, VizOptions } from '../../../core/models';
import { StudioKind, kindDef } from './studio-model';

/** A change from the design panel: visual options, subtype or the data limit. */
export interface DesignChange {
  viz?: VizOptions;
  subtype?: ChartSubtype;
  limit?: number | null;
}

const SERIES_KINDS: StudioKind[] = ['column', 'bar', 'line', 'area'];
const AXIS_KINDS: StudioKind[] = [...SERIES_KINDS, 'scatter'];
const NO_NUMBER_FORMAT: StudioKind[] = ['kpi'];
const LIMITLESS: StudioKind[] = ['kpi', 'gauge'];

/**
 * Widget Studio's design panel. Shows only the option groups that mean something
 * for the chosen chart (docs/design/widget-studio.md §2.4).
 */
@Component({
  selector: 'app-design-panel',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule],
  templateUrl: './design-panel.html',
  styleUrl: './design-panel.scss',
})
export class DesignPanel {
  readonly kind = input.required<StudioKind>();
  readonly subtype = input<ChartSubtype | undefined>();
  readonly viz = input.required<VizOptions>();
  readonly limit = input<number | null>(null);
  /** Drawn series (value key → label) for colour assignment; empty when a break-by makes the series. */
  readonly series = input<{ key: string; label: string }[]>([]);
  /** Every value is additive, so a pivot grand total is a true total. */
  readonly additive = input(false);
  readonly designChange = output<DesignChange>();

  readonly slots = [1, 2, 3, 4, 5, 6, 7, 8];
  readonly legendPositions: NonNullable<VizOptions['legend']>['position'][] = ['top', 'bottom'];
  readonly lineWidths: NonNullable<VizOptions['lineWidth']>[] = ['thin', 'regular', 'thick'];
  readonly numberStyles: NumberFormat['style'][] = ['auto', 'number', 'currency', 'percent'];
  readonly decimals: NumberFormat['decimals'][] = [0, 1, 2, 3, 4];
  readonly statuses: { key: RuleStatus; label: string }[] = [
    { key: 'good', label: 'Good' },
    { key: 'warning', label: 'Warning' },
    { key: 'critical', label: 'Critical' },
  ];
  readonly ruleOps: { op: ColorRule['op']; label: string }[] = [
    { op: 'gt', label: '>' },
    { op: 'gte', label: '≥' },
    { op: 'lt', label: '<' },
    { op: 'lte', label: '≤' },
    { op: 'eq', label: '=' },
  ];

  readonly def = computed(() => kindDef(this.kind()));
  readonly isSeries = computed(() => SERIES_KINDS.includes(this.kind()));
  readonly hasAxes = computed(() => AXIS_KINDS.includes(this.kind()));
  readonly hasLegend = computed(() => this.isSeries() || this.kind() === 'pie');
  readonly hasLabels = computed(() => this.def().widgetType === 'chart' && this.kind() !== 'map');
  readonly hasNumber = computed(() => !NO_NUMBER_FORMAT.includes(this.kind()));
  readonly hasLimit = computed(() => !LIMITLESS.includes(this.kind()));
  readonly hasConditional = computed(
    () =>
      ((this.kind() === 'column' || this.kind() === 'bar') && this.series().length === 1) ||
      this.kind() === 'gauge' ||
      this.kind() === 'table',
  );
  readonly number = computed<NumberFormat>(
    () => this.viz().number ?? { style: 'auto', decimals: 'auto', abbreviate: 'auto' },
  );

  set(patch: Partial<VizOptions>) {
    this.designChange.emit({ viz: { ...this.viz(), ...patch } });
  }
  setAxes(patch: NonNullable<VizOptions['axes']>) {
    this.set({ axes: { ...this.viz().axes, ...patch } });
  }
  setNumber(patch: Partial<NumberFormat>) {
    this.set({ number: { ...this.number(), ...patch } });
  }
  setColor(key: string, slot: number | null) {
    const others = Object.entries(this.viz().colors ?? {}).filter(([k]) => k !== key);
    this.set({ colors: Object.fromEntries(slot === null ? others : [...others, [key, slot]]) });
  }
  setLegend(patch: Partial<NonNullable<VizOptions['legend']>>) {
    this.set({ legend: { enabled: true, position: 'top', ...this.viz().legend, ...patch } });
  }
  setLabels(patch: NonNullable<VizOptions['labels']>) {
    this.set({ labels: { ...this.viz().labels, ...patch } });
  }
  setDecimals(value: string | number) {
    this.setNumber({ decimals: value === 'auto' ? 'auto' : (Number(value) as NumberFormat['decimals']) });
  }
  setGauge(patch: NonNullable<VizOptions['gauge']>) {
    this.set({ gauge: { ...this.viz().gauge, ...patch } });
  }
  setReference(value: number | null, label = this.viz().reference?.label ?? '') {
    this.set({ reference: value === null ? null : { value, label } });
  }

  /** Number inputs bind '' when cleared; treat that as "no value". */
  num(v: unknown): number | null {
    return typeof v === 'number' && Number.isFinite(v) ? v : null;
  }

  // Conditional colour rules
  addRule() {
    this.set({ conditional: [...(this.viz().conditional ?? []), { op: 'lt', value: 0, status: 'critical' }] });
  }
  updateRule(i: number, patch: Partial<ColorRule>) {
    this.set({ conditional: (this.viz().conditional ?? []).map((r, j) => (j === i ? { ...r, ...patch } : r)) });
  }
  removeRule(i: number) {
    this.set({ conditional: (this.viz().conditional ?? []).filter((_, j) => j !== i) });
  }
}
