import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { DriversData } from '../core/ai-models';
import { fmt, fmtChange, fmtDate } from '../core/format';
import { Driver } from '../core/models';

/**
 * Visual explanation of a change: the metric delta at the top, the drivers that
 * produced it beneath, each sized by its share of the change. Narrative and
 * picture are the same data.
 */
@Component({
  selector: 'app-drivers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './drivers.html',
  styleUrl: './drivers.scss',
})
export class Drivers {
  readonly data = input.required<DriversData>();
  /** A driver the viewer wants to explore further. */
  readonly explore = output<Driver>();
  readonly top = computed(() => (this.data().drivers ?? []).slice(0, 4));
  readonly change = computed(() => fmtChange(this.data().change, this.data().change_pct, this.data().format));
  f = (v: number | null) => fmt(v, this.data().format);
  date = (d: string) => fmtDate(d, 'long');
  x = (i: number) => ((i + 0.5) / this.top().length) * 100;
  share = (dr: Driver) => Math.max(0, Math.round(Math.abs(dr.impact_share ?? 0) * 100));
}
