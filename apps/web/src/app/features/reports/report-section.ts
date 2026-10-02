import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { fmt, fmtDate } from '../../core/format';
import { Chart } from '../../shared/chart';
import { ChartSpec, specFromForecast } from '../../shared/chart-spec';
import { Drivers } from '../../shared/drivers';
import { Kpi } from '../../shared/kpi';

@Component({
  selector: 'app-report-section',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Kpi, Chart, Drivers],
  template: `
    @let s = section(); @let c = s.content;
    @if (c.error) { <p class="err">{{ c.error }}</p> }
    @else {
      @switch (s.type) {
        @case ('summary') { <div class="summary">@for (p of c.paragraphs; track $index) { <p>{{ p }}</p> }</div> }
        @case ('kpis') { <div class="kpis">@for (k of c.cards; track k.ref) { <app-kpi [card]="k" [link]="false"/> }</div> }
        @case ('chart') { @if (c.caption) { <p class="caption">{{ c.caption }}</p> }<app-chart [spec]="trend()" [height]="260"/> }
        @case ('breakdown') { <p class="caption">{{ c.label }} by {{ c.dimension_label }}</p><app-chart [spec]="breakdown()" [height]="Math.max(200, c.rows.length * 30 + 30)"/> }
        @case ('forecast') { <p class="caption">{{ c.label }} · {{ c.method?.replaceAll('_', ' ') }} · shaded band is the 80% interval</p><app-chart [spec]="forecast()" [height]="260"/> }
        @case ('root_cause') { <app-drivers [data]="c"/> }
        @case ('anomalies') {
          @if (!c.items?.length) { <p class="muted">{{ c.empty_message }}</p> }
          @else { <table class="table"><thead><tr><th>Metric</th><th>Date</th><th class="r">Expected</th><th class="r">Actual</th><th class="r">Deviation</th></tr></thead>
            <tbody>@for (a of c.items; track a.id) { <tr><td>{{ a.label }}</td><td>{{ date(a.period) }}</td><td class="r">{{ f(a.expected, a.format) }}</td><td class="r"><b>{{ f(a.actual, a.format) }}</b></td><td class="r">{{ Math.abs(a.score).toFixed(1) }}σ {{ a.direction }}</td></tr> }</tbody></table> }
        }
        @case ('risks') {
          @for (r of c.items; track $index) { <div class="risk" [class.high]="r.severity === 'high'"><span class="badge" [class.critical]="r.severity === 'high'" [class.warning]="r.severity !== 'high'">{{ r.severity }}</span><div><b>{{ r.title }}</b><p class="muted">{{ r.detail }}</p></div></div> }
          @empty { <p class="muted">{{ c.empty_message }}</p> }
        }
        @case ('text') { @for (p of (c.markdown ?? '').split('\\n\\n'); track $index) { <p>{{ p }}</p> } }
      }
    }`,
  styles: [`:host{display:block}.err{color:var(--neg)}
    .summary p{font-size:16px;line-height:1.65;margin-bottom:10px;max-width:72ch}.summary p:first-child{font-size:19px;font-weight:500;letter-spacing:-.01em}
    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));border:1px solid var(--line);border-radius:var(--r-lg)}.kpis app-kpi+app-kpi{border-left:1px solid var(--line)}
    .caption{color:var(--ink-2);margin-bottom:34px;font-size:13.5px}
    .risk{display:flex;gap:12px;padding:12px 14px;border-left:3px solid var(--warning);background:var(--bg-2);border-radius:0 var(--r-md) var(--r-md) 0;margin-bottom:8px}.risk.high{border-color:var(--critical)}`],
})
export class ReportSection {
  readonly section = input.required<any>();
  Math = Math; f = fmt; date = (d: string) => fmtDate(d, 'long');
  readonly trend = computed<ChartSpec>(() => {
    const c = this.section().content;
    return { kind: c.chart === 'area' ? 'area' : 'line', isTime: true, grain: c.grain, format: c.format, target: c.target, categories: c.series.map((p: any) => p.period),
      series: [{ key: 'v', name: c.label, format: c.format, data: c.series.map((p: any) => p.value) }] };
  });
  readonly breakdown = computed<ChartSpec>(() => {
    const c = this.section().content;
    return { kind: 'hbar', format: c.format, categories: c.rows.map((r: any) => r.member), series: [{ key: 'v', name: c.label, format: c.format, data: c.rows.map((r: any) => r.value) }] };
  });
  readonly forecast = computed(() => { const c = this.section().content; return specFromForecast(c.history, c.points, c.format, c.label, c.grain); });
}
