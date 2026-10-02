import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { arrow, fmt, fmtChange } from '../core/format';
import { KpiCard } from '../core/models';
import { Sparkline } from './sparkline';

/** KPI tile: label · value · signed delta vs named period · target · sparkline. */
@Component({
  selector: 'app-kpi',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Sparkline, RouterLink],
  templateUrl: './kpi.html',
  styleUrl: './kpi.scss',
})
export class Kpi {
  readonly card = input.required<KpiCard>();
  readonly hero = input(false);
  readonly link = input(true);
  readonly value = computed(() => fmt(this.card().value, this.card().format));
  readonly change = computed(() => fmtChange(this.card().change, this.card().change_pct, this.card().format));
  readonly target = computed(() => fmt(this.card().target, this.card().format));
  readonly arrowFor = computed(() => arrow(this.card().direction));
  readonly sparkColor = computed(() => (this.card().sentiment === 'negative' ? 'var(--neg)' : 'var(--series-1)'));
}
