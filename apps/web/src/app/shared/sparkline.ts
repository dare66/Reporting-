import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/** Inline SVG sparkline: de-emphasised history, accent end-dot on the latest complete point. */
@Component({
  selector: 'app-spark',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (geo(); as g) {
      <svg [attr.viewBox]="'0 0 ' + width() + ' ' + height()" [style.height.px]="height()" preserveAspectRatio="none" role="img" [attr.aria-label]="label()">
        <path [attr.d]="g.area" [attr.fill]="color()" fill-opacity=".1"/>
        <path [attr.d]="g.line" fill="none" [attr.stroke]="color()" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
        @if (g.partial) { <path [attr.d]="g.partial" fill="none" [attr.stroke]="color()" stroke-width="1.6" stroke-dasharray="2 3" vector-effect="non-scaling-stroke" opacity=".7"/> }
        <circle [attr.cx]="g.end[0]" [attr.cy]="g.end[1]" r="3" [attr.fill]="color()" stroke="var(--bg-1)" stroke-width="2"/>
      </svg>
    }`,
  styles: [':host{display:block;width:100%} svg{width:100%;display:block;overflow:visible}'],
})
export class Sparkline {
  readonly points = input<{ period: string; value: number | null }[]>([]);
  readonly partialFrom = input<string | null>(null);
  readonly color = input('var(--series-1)');
  readonly width = input(160);
  readonly height = input(36);
  readonly label = input('trend');

  readonly geo = computed(() => {
    const pts = this.points().filter(p => p.value !== null) as { period: string; value: number }[];
    if (pts.length < 2) return null;
    const vals = pts.map(p => p.value);
    const min = Math.min(...vals), max = Math.max(...vals), span = max - min || 1;
    const w = this.width(), h = this.height(), pad = 4;
    const xy = pts.map((p, i) => [(i / (pts.length - 1)) * w, pad + (h - 2 * pad) * (1 - (p.value - min) / span)] as [number, number]);
    const cut = this.partialFrom() ? pts.findIndex(p => p.period >= this.partialFrom()!) : -1;
    const solid = cut > 0 ? xy.slice(0, cut) : xy;
    const d = (arr: number[][]) => arr.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join('');
    const endIdx = cut > 0 ? cut - 1 : xy.length - 1;
    return {
      line: d(solid),
      area: d(solid) + `L${solid[solid.length - 1][0]},${h}L0,${h}Z`,
      partial: cut > 0 ? d(xy.slice(cut - 1)) : null,
      end: xy[endIdx],
    };
  });
}
