import { CdkTrapFocus } from '@angular/cdk/a11y';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { ago, fmtDate } from '../../core/format';
import { DriftEventView, Envelope, TrustDetail } from '../../core/models';
import { Icon } from '../../shared/icon';
import { Scrim } from '../../shared/scrim';
import { Sparkline } from '../../shared/sparkline';
import { grade } from './trust';

/** One dataset's trust: the parts of its score, its history across loads, and every change in its shape. */
@Component({
  selector: 'app-trust-drawer',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CdkTrapFocus, RouterLink, Scrim, Icon, Sparkline],
  templateUrl: './trust-drawer.html',
  styleUrl: './trust-drawer.scss',
})
export class TrustDrawer implements OnInit {
  private api = inject(Api);
  readonly datasetId = input.required<string>();
  readonly changed = output();
  readonly dismiss = output();

  readonly detail = signal<TrustDetail | null>(null);
  readonly error = signal<string | null>(null);
  readonly busy = signal<string | null>(null);
  readonly showAll = signal(false);
  readonly ago = ago;
  readonly fmtDate = fmtDate;
  readonly grade = grade;

  readonly history = computed(() =>
    (this.detail()?.history ?? []).map((h) => ({ period: h.taken_at, value: h.trust_score })),
  );
  readonly drift = computed(() => (this.detail()?.drift ?? []).filter((e) => this.showAll() || e.status === 'open'));

  ngOnInit() {
    this.load();
  }

  async load() {
    try {
      this.detail.set((await this.api.get<Envelope<TrustDetail>>(`/trust/datasets/${this.datasetId()}`)).data);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  async acknowledge(e: DriftEventView) {
    this.busy.set(e.id);
    try {
      await this.api.post(`/trust/drift/${e.id}/acknowledge`);
      await this.load();
      this.changed.emit();
    } catch (err) {
      this.error.set(errorMessage(err));
    } finally {
      this.busy.set(null);
    }
  }

  affected(e: DriftEventView): number {
    const i = e.impact;
    return i ? i.metrics.length + i.dashboards.length + i.reports.length + i.alerts.length + i.hidden : 0;
  }
}
