import { ChangeDetectionStrategy, Component, inject, input, output, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AiBlock, ChartBlock, ForecastBlock, ScenarioBlock } from '../../core/ai-models';
import { errorMessage } from '../../core/api.service';
import { cell, fmt, fmtDate } from '../../core/format';
import { ExportFormat } from '../../core/models';
import { ReportExporter } from '../../core/report-exporter.service';
import { ActionCard } from '../../shared/action-card/action-card';
import { Chart } from '../../shared/chart';
import { specFromBlock, specFromForecast } from '../../shared/chart-spec';
import { Drivers } from '../../shared/drivers';
import { Globe } from '../../shared/globe';
import { Icon } from '../../shared/icon';
import { Kpi } from '../../shared/kpi';

/** One before → after line of a what-if comparison. */
interface ScenarioRow {
  label: string;
  base: string;
  proj: string;
  /** The projection moves the wrong way. */
  bad: boolean;
}

/** Renders one structured block produced by the AI analyst. */
@Component({
  selector: 'app-ai-block',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Kpi, Chart, Drivers, Icon, RouterLink, Globe, ActionCard],
  templateUrl: './ai-blocks.html',
  styleUrl: './ai-blocks.scss',
})
export class AiBlockView {
  private exporter = inject(ReportExporter);
  readonly block = input.required<AiBlock>();
  /** A follow-up question the viewer triggered from this block. */
  readonly ask = output<string>();
  readonly globe = signal(true);
  readonly exportFormats: ExportFormat[] = ['pdf', 'pptx', 'xlsx'];
  readonly exporting = signal<ExportFormat | null>(null);
  readonly exportError = signal<string | null>(null);
  Math = Math;
  f = fmt;
  cell = cell;
  fmtDate = fmtDate;
  spec = specFromBlock;
  sign = (v: number) => (v > 0 ? '+' + v : String(v));

  globeData(b: ChartBlock) {
    const dim = b.dimension?.key ?? '';
    const metric = b.series[0]?.key ?? '';
    return (b.rows ?? []).map((r) => ({ name: String(r[dim]), value: Number(r[metric]) }));
  }

  forecastSpec(b: ForecastBlock) {
    return specFromForecast(b.history, b.points, b.format, b.title.replace(' forecast', ''), b.grain);
  }

  drill(b: ChartBlock, category: string) {
    if (b.dimension) this.ask.emit(`Show ${b.series[0]?.label ?? 'it'} for ${category} over time`);
  }

  scenarioRows(b: ScenarioBlock): ScenarioRow[] {
    const base = b.baseline;
    const proj = b.projected;
    const lower = (a: number | null, z: number | null) => a !== null && z !== null && a < z;
    return [
      { label: 'Applications', base: fmt(base.applications), proj: fmt(proj.applications), bad: false },
      { label: 'Capacity', base: fmt(base.capacity), proj: fmt(proj.capacity), bad: false },
      {
        label: 'Utilisation',
        base: fmt(base.utilisation, 'percent'),
        proj: fmt(proj.utilisation, 'percent'),
        bad: lower(base.utilisation, proj.utilisation),
      },
      {
        label: 'Processing SLA',
        base: fmt(base.sla_compliance, 'percent'),
        proj: fmt(proj.sla_compliance, 'percent'),
        bad: lower(proj.sla_compliance, base.sla_compliance),
      },
      {
        label: 'Revenue',
        base: fmt(base.revenue, 'currency'),
        proj: fmt(proj.revenue, 'currency'),
        bad: proj.revenue < base.revenue,
      },
    ];
  }

  async exportReport(reportId: string, title: string, format: ExportFormat) {
    this.exporting.set(format);
    this.exportError.set(null);
    try {
      await this.exporter.download(reportId, format, title, 60);
    } catch (e) {
      this.exportError.set(errorMessage(e));
    } finally {
      this.exporting.set(null);
    }
  }
}
