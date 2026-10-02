import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { fmt, fmtChange, fmtDate } from '../core/format';

/**
 * Visual explanation of a change: the metric delta at the top, the drivers that
 * produced it beneath, each sized by its share of the change. Narrative and
 * picture are the same data.
 */
@Component({
  selector: 'app-drivers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @let d = data();
    <div class="tree">
      <div class="root" [class.neg]="d.sentiment === 'negative'">
        <div class="eyebrow">{{ d.label }}</div>
        <div class="delta-big">{{ change() }}</div>
        <div class="muted">{{ f(d.previous?.value) }} → {{ f(d.current?.value) }}</div>
      </div>
      @if (d.drivers?.length) {
        <svg class="wires" viewBox="0 0 100 24" preserveAspectRatio="none" aria-hidden="true">
          @for (dr of top(); track $index; let i = $index) {
            <path [attr.d]="'M50,0 C50,12 ' + x(i) + ',12 ' + x(i) + ',24'" fill="none" stroke="var(--line-2)" stroke-width=".6" vector-effect="non-scaling-stroke"/>
          }
        </svg>
        <div class="leaves" [style.grid-template-columns]="'repeat(' + top().length + ', minmax(0,1fr))'">
          @for (dr of top(); track $index; let i = $index) {
            <button class="leaf" (click)="explore.emit(dr)" [class.main]="i === 0">
              @if (i === 0) { <span class="eyebrow signal">Main driver</span> }
              <span class="dim">{{ dr.dimension_label }}</span>
              <span class="member">{{ dr.member }}</span>
              <span class="vals">{{ f(dr.previous_value) }} → <b>{{ f(dr.current_value) }}</b></span>
              <span class="share"><span class="fill" [style.width.%]="share(dr)"></span></span>
              <span class="muted small">{{ share(dr) }}% of the change</span>
            </button>
          }
        </div>
      } @else {
        <p class="muted" style="text-align:center;margin-top:12px">No single segment explains the change — it is broad-based.</p>
      }
      @if (d.onset?.date && d.onset?.significant) {
        <div class="onset"><span class="dot" style="background:var(--neg)"></span> Shift began around <b>{{ date(d.onset.date) }}</b> · {{ d.onset.shift_sigma }}σ step</div>
      }
    </div>`,
  styles: [`
    .tree{display:grid;justify-items:center}
    .root{text-align:center;display:grid;gap:2px;padding:14px 22px;border-radius:var(--r-lg);border:1px solid var(--line-2);background:var(--bg-2);min-width:220px}
    .delta-big{font-size:34px;font-weight:650;letter-spacing:-0.04em}
    .root.neg .delta-big{color:var(--neg)}
    .wires{width:100%;height:30px}
    .leaves{display:grid;gap:10px;width:100%}
    .leaf{display:grid;gap:4px;text-align:left;padding:12px 14px;border-radius:var(--r-md);border:1px solid var(--line);background:var(--bg-1);cursor:pointer;color:inherit;transition:border-color .2s,transform .2s var(--ease)}
    .leaf:hover{border-color:var(--ink-3);transform:translateY(-1px)}
    .leaf.main{border-color:color-mix(in srgb,var(--accent) 45%,transparent);background:linear-gradient(180deg,var(--accent-soft),transparent)}
    .dim{font-size:11.5px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.06em}
    .member{font-weight:650;font-size:15px;line-height:1.2}
    .vals{font-size:12.5px;color:var(--ink-2)}
    .share{height:4px;border-radius:2px;background:var(--bg-3);overflow:hidden;margin-top:4px}
    .share .fill{display:block;height:100%;background:var(--accent);border-radius:2px}
    .small{font-size:11.5px}
    .onset{margin-top:14px;font-size:12.5px;color:var(--ink-2);display:flex;align-items:center;gap:8px}
    @media (max-width:720px){.leaves{grid-template-columns:1fr !important}.wires{display:none}}`],
})
export class Drivers {
  readonly data = input.required<any>();
  readonly explore = output<any>();
  readonly top = computed(() => (this.data().drivers ?? []).slice(0, 4));
  readonly change = computed(() => fmtChange(this.data().change, this.data().change_pct, this.data().format));
  f = (v: number | null) => fmt(v, this.data().format);
  date = (d: string) => fmtDate(d, 'long');
  x = (i: number) => ((i + 0.5) / this.top().length) * 100;
  share = (dr: any) => Math.max(0, Math.round(Math.abs(dr.impact_share ?? 0) * 100));
}
