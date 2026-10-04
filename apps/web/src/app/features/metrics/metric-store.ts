import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../core/api.service';
import { ago } from '../../core/format';
import { MetricStatus, StoreMetric } from '../../core/models';
import { Icon } from '../../shared/icon';
import { ErrorState } from '../../shared/states';
import { MetricDrawer } from './metric-drawer';

interface StoreResponse {
  data: StoreMetric[];
  counts: Record<MetricStatus, number>;
}

export const STATUS_LABEL: Record<MetricStatus, string> = {
  certified: 'Certified',
  approved: 'Approved',
  proposed: 'Proposed',
  deprecated: 'Deprecated',
};

/**
 * Every governed metric in one place: what it means, who owns it, whether it
 * is certified, and where it is used. Opening a metric shows its lifecycle,
 * definition, versions and usage.
 */
@Component({
  selector: 'app-metric-store',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, ErrorState, MetricDrawer],
  templateUrl: './metric-store.html',
  styleUrl: './metric-store.scss',
})
export class MetricStorePage implements OnInit {
  private api = inject(Api);
  readonly statuses: MetricStatus[] = ['certified', 'approved', 'proposed', 'deprecated'];
  readonly statusLabel = STATUS_LABEL;
  readonly ago = ago;

  readonly metrics = signal<StoreMetric[]>([]);
  readonly counts = signal<Record<MetricStatus, number> | null>(null);
  readonly error = signal<string | null>(null);
  readonly status = signal<MetricStatus | null>(null);
  readonly kpiOnly = signal(false);
  readonly text = signal('');
  readonly open = signal<StoreMetric | null>(null);

  readonly total = computed(() => Object.values(this.counts() ?? {}).reduce((a, b) => a + b, 0));
  readonly shown = computed(() => {
    const q = this.text().trim().toLowerCase();
    return this.metrics().filter(
      (m) =>
        (!this.status() || m.status === this.status()) &&
        (!this.kpiOnly() || m.is_kpi) &&
        (!q || `${m.label} ${m.ref} ${m.description ?? ''} ${m.model.name}`.toLowerCase().includes(q)),
    );
  });

  ngOnInit() {
    this.load();
  }

  async load() {
    this.error.set(null);
    try {
      const res = await this.api.get<StoreResponse>('/metric-store');
      this.metrics.set(res.data);
      this.counts.set(res.counts);
      // Keep an open drawer's row in step with what was just saved.
      const open = this.open();
      if (open) this.open.set(res.data.find((m) => m.ref === open.ref) ?? null);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  /** Other metrics of the same model, offered as replacements when deprecating. */
  siblings(m: StoreMetric): StoreMetric[] {
    return this.metrics().filter((x) => x.model.key === m.model.key && x.key !== m.key && x.status !== 'deprecated');
  }
}
