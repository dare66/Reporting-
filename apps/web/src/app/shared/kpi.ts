import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { arrow, fmt, fmtChange } from '../core/format';
import { Sparkline } from './sparkline';

/** KPI tile: label · value · signed delta vs named period · target · sparkline. */
@Component({
  selector: 'app-kpi',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Sparkline, RouterLink],
  template: `
    @let c = card();
    <a class="kpi" [routerLink]="link() ? '/investigate' : null" [queryParams]="link() ? { metric: c.ref } : null" [class.hero]="hero()">
      <div class="label">{{ c.label }}</div>
      <div class="value" [class.big]="hero()">{{ value() }}</div>
      <div class="meta">
        <span class="delta" [class]="c.sentiment">{{ arrowFor() }} {{ change() }}</span>
        <span class="muted">vs prev.</span>
        @if (c.target_status) {
          <span class="badge" [class.good]="c.target_status === 'met'" [class.warning]="c.target_status === 'missed'">
            {{ c.target_status === 'met' ? '✓ target' : '! below ' + target() }}
          </span>
        }
      </div>
      @if (c.sparkline?.length > 1) {
        <app-spark [points]="c.sparkline" [color]="sparkColor()" [height]="hero() ? 56 : 34" [label]="c.label + ' trend'"/>
      }
    </a>`,
  styles: [`
    .kpi { display:grid; gap:6px; padding:18px 20px; min-width:0; color:inherit; border-radius:var(--r-lg); transition:background .2s var(--ease); }
    a.kpi[href]:hover { background: color-mix(in srgb, var(--bg-3) 55%, transparent); }
    .label { font-size:12px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:var(--ink-3); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .value { font-size:30px; font-weight:600; letter-spacing:-0.035em; line-height:1.05; }
    .value.big { font-size:clamp(36px,3.6vw,52px); letter-spacing:-0.045em; white-space:nowrap; }
    .meta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:12px; min-height:22px; }
    app-spark { margin-top:6px; }`],
})
export class Kpi {
  readonly card = input.required<any>();
  readonly hero = input(false);
  readonly link = input(true);
  readonly value = computed(() => fmt(this.card().value, this.card().format));
  readonly change = computed(() => fmtChange(this.card().change, this.card().change_pct, this.card().format));
  readonly target = computed(() => fmt(this.card().target, this.card().format));
  readonly arrowFor = computed(() => arrow(this.card().direction));
  readonly sparkColor = computed(() => (this.card().sentiment === 'negative' ? 'var(--neg)' : 'var(--series-1)'));
}
