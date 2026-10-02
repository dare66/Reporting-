import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { RANGES, fmt, fmtChange, fmtDate } from '../../core/format';
import { Driver, Envelope, RootCause } from '../../core/models';
import { Chart } from '../../shared/chart';
import { ChartSpec } from '../../shared/chart-spec';
import { Drivers } from '../../shared/drivers';
import { EvidenceSheet } from '../../shared/evidence';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';

/** Root-cause workspace: the change, its drivers, when it began, and the evidence. */
@Component({
  selector: 'app-investigate',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Drivers, Chart, Icon, EvidenceSheet, Working, ErrorState],
  templateUrl: './investigate.html',
  styleUrl: './investigate.scss',
})
export class Investigate implements OnInit {
  private api = inject(Api);
  private router = inject(Router);
  readonly auth = inject(Auth);
  readonly metric = input<string>('decisions.sla_compliance');
  readonly ranges = RANGES.filter((r) =>
    ['last_7_days', 'last_30_days', 'last_90_days', 'this_month', 'this_quarter'].includes(r.key),
  );
  readonly range = signal('last_30_days');
  readonly rc = signal<RootCause | null>(null);
  readonly error = signal<string | null>(null);
  readonly dim = signal<string | null>(null);
  readonly evidence = signal(false);
  Math = Math;

  readonly change = computed(() => fmtChange(this.rc()?.change, this.rc()?.change_pct, this.rc()?.format));
  readonly dimData = computed(() =>
    this.rc()?.dimensions.find((d) => d.key === (this.dim() ?? this.rc()?.dimensions[0]?.key)),
  );
  readonly onsetSpec = computed<ChartSpec>(() => {
    const r = this.rc();
    const pts = r?.onset?.series ?? [];
    return {
      kind: 'line',
      isTime: true,
      format: r?.format ?? 'number',
      categories: pts.map((p) => p.date),
      series: [{ key: 'v', name: r?.label ?? '', format: r?.format ?? 'number', data: pts.map((p) => p.value) }],
      markers: r?.onset?.significant ? [{ period: r.onset.date, label: 'Shift began' }] : [],
    };
  });

  ngOnInit() {
    this.load();
  }

  async load() {
    this.rc.set(null);
    this.error.set(null);
    try {
      const body = { metric: this.metric(), range: this.range() };
      this.rc.set((await this.api.post<Envelope<RootCause>>('/analysis/root-cause', body)).data);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  focus(d: Driver) {
    this.dim.set(d.dimension);
  }
  f = (v: number | null) => fmt(v, this.rc()?.format);
  impact = (v: number) =>
    this.rc()?.format === 'percent'
      ? `${v >= 0 ? '+' : '−'}${Math.abs(v * 100).toFixed(2)} pts`
      : `${v >= 0 ? '+' : '−'}${fmt(Math.abs(v), this.rc()?.format)}`;
  date = (d: string) => fmtDate(d, 'long');
}
