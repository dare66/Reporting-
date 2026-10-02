import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { EvidenceView, insightEvidence } from '../../core/evidence';
import { ago, fmt, fmtDate } from '../../core/format';
import { Anomaly, Envelope, Insight } from '../../core/models';
import { EvidenceSheet } from '../../shared/evidence';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';

@Component({
  selector: 'app-insights',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, EvidenceSheet, Working, ErrorState, RouterLink],
  templateUrl: './insights.html',
  styleUrl: './insights.scss',
})
export class Insights implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly insights = signal<Insight[]>([]);
  readonly anomalies = signal<Anomaly[]>([]);
  readonly busy = signal<'gen' | 'scan' | null>(null);
  readonly error = signal<string | null>(null);
  readonly ev = signal<EvidenceView | null>(null);
  Math = Math;
  ago = ago;
  f = fmt;
  date = (d: string) => fmtDate(d, 'long');

  ngOnInit() {
    this.load();
  }
  async load() {
    try {
      const [i, a] = await Promise.all([
        this.api.get<Envelope<Insight[]>>('/insights'),
        this.api.get<Envelope<Anomaly[]>>('/anomalies', { status: 'open' }),
      ]);
      this.insights.set(i.data);
      this.anomalies.set(a.data);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
  async generate() {
    this.busy.set('gen');
    try {
      this.insights.set((await this.api.post<Envelope<Insight[]>>('/insights/generate')).data);
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(null);
    }
  }
  async scan() {
    this.busy.set('scan');
    try {
      await this.api.post('/analysis/anomalies/scan');
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(null);
    }
  }
  async setStatus(a: Anomaly, status: Anomaly['status']) {
    await this.api.patch(`/anomalies/${a.id}`, { status });
    this.anomalies.update((x) => x.filter((y) => y.id !== a.id));
  }
  show(insight: Insight) {
    this.ev.set(insightEvidence(insight));
  }
}
