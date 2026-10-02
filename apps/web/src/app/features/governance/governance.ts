import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../core/api.service';
import { AiGovernance, AuditLog, DataQualityEntry, Envelope, Paginated } from '../../core/models';
import { Auth } from '../../core/auth.service';
import { ago, compact, fmtDate } from '../../core/format';
import { Chart } from '../../shared/chart';
import { ChartSpec } from '../../shared/chart-spec';
import { Icon } from '../../shared/icon';
import { ErrorState } from '../../shared/states';

@Component({
  selector: 'app-governance',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Chart, FormsModule, Icon, ErrorState],
  templateUrl: './governance.html',
  styleUrl: './governance.scss',
})
export class Governance implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly tabs = computed(() => [
    ...(this.auth.can('governance.view')
      ? [
          { key: 'ai', label: 'AI usage' },
          { key: 'quality', label: 'Data quality' },
        ]
      : []),
    ...(this.auth.can('audit.view') ? [{ key: 'audit', label: 'Audit trail' }] : []),
  ]);
  readonly tab = signal(this.auth.can('governance.view') ? 'ai' : 'audit');
  readonly ai = signal<AiGovernance | null>(null);
  readonly audit = signal<Paginated<AuditLog> | null>(null);
  readonly quality = signal<DataQualityEntry[] | null>(null);
  readonly action = signal('');
  readonly decision = signal('');
  readonly error = signal<string | null>(null);
  ago = ago;
  compact = compact;
  fmtDate = fmtDate;
  /** "deterministic (fallback: …)" → "deterministic". */
  plannerName = (planner: string | null) => planner?.split(' ')[0] ?? '—';

  readonly runsSpec = computed<ChartSpec>(() => {
    const d = this.ai()?.by_day ?? [];
    return {
      kind: 'bar',
      isTime: true,
      grain: 'day',
      format: 'number',
      categories: d.map((x) => x.day),
      series: [{ key: 'runs', name: 'Runs', format: 'number', data: d.map((x) => x.runs) }],
    };
  });
  readonly intentSpec = computed<ChartSpec>(() => {
    const d = this.ai()?.by_intent ?? [];
    return {
      kind: 'hbar',
      format: 'number',
      categories: d.map((x) => x.intent ?? 'unknown'),
      series: [{ key: 'runs', name: 'Runs', format: 'number', data: d.map((x) => x.runs) }],
    };
  });

  ngOnInit() {
    this.load();
  }
  async load() {
    this.error.set(null);
    try {
      if (this.tab() === 'ai') this.ai.set((await this.api.get<Envelope<AiGovernance>>('/governance/ai')).data);
      if (this.tab() === 'audit') {
        const params = { action: this.action(), decision: this.decision(), per_page: 100 };
        this.audit.set(await this.api.get<Paginated<AuditLog>>('/governance/audit-logs', params));
      }
      if (this.tab() === 'quality') {
        this.quality.set((await this.api.get<Envelope<DataQualityEntry[]>>('/governance/data-quality')).data);
      }
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
}
