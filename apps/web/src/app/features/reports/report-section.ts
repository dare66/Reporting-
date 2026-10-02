import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { fmt, fmtDate } from '../../core/format';
import { ReportSection as Section } from '../../core/models';
import { Chart } from '../../shared/chart';
import { ChartSpec, specFromForecast } from '../../shared/chart-spec';
import { Drivers } from '../../shared/drivers';
import { Kpi } from '../../shared/kpi';

/** Renders one computed report section (the same content the exporters print). */
@Component({
  selector: 'app-report-section',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Kpi, Chart, Drivers],
  templateUrl: './report-section.html',
  styleUrl: './report-section.scss',
})
export class ReportSection {
  readonly section = input.required<Section>();
  Math = Math;
  f = fmt;
  date = (d: string) => fmtDate(d, 'long');

  readonly error = computed(() => this.section().content.error ?? null);
  readonly summary = computed(() => {
    const s = this.section();
    return s.type === 'summary' ? s.content : null;
  });
  readonly kpis = computed(() => {
    const s = this.section();
    return s.type === 'kpis' ? s.content : null;
  });
  readonly chart = computed(() => {
    const s = this.section();
    return s.type === 'chart' ? s.content : null;
  });
  readonly breakdownContent = computed(() => {
    const s = this.section();
    return s.type === 'breakdown' ? s.content : null;
  });
  readonly forecastContent = computed(() => {
    const s = this.section();
    return s.type === 'forecast' ? s.content : null;
  });
  readonly rootCause = computed(() => {
    const s = this.section();
    return s.type === 'root_cause' ? s.content : null;
  });
  readonly anomalies = computed(() => {
    const s = this.section();
    return s.type === 'anomalies' ? s.content : null;
  });
  readonly risks = computed(() => {
    const s = this.section();
    return s.type === 'risks' ? s.content : null;
  });
  readonly text = computed(() => {
    const s = this.section();
    return s.type === 'text' ? s.content : null;
  });

  readonly trend = computed<ChartSpec | null>(() => {
    const c = this.chart();
    if (!c) return null;
    return {
      kind: c.chart === 'area' ? 'area' : 'line',
      isTime: true,
      grain: c.grain,
      format: c.format,
      target: c.target,
      categories: c.series.map((p) => p.period),
      series: [{ key: 'v', name: c.label, format: c.format, data: c.series.map((p) => p.value) }],
    };
  });
  readonly breakdown = computed<ChartSpec | null>(() => {
    const c = this.breakdownContent();
    if (!c) return null;
    return {
      kind: 'hbar',
      format: c.format,
      categories: c.rows.map((r) => r.member),
      series: [{ key: 'v', name: c.label, format: c.format, data: c.rows.map((r) => r.value) }],
    };
  });
  readonly forecast = computed(() => {
    const c = this.forecastContent();
    return c ? specFromForecast(c.history, c.points, c.format, c.label, c.grain) : null;
  });
}
