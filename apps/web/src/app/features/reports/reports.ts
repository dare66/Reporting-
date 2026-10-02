import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Envelope, ReportSummary, ReportTemplate } from '../../core/models';
import { Auth } from '../../core/auth.service';
import { ago } from '../../core/format';
import { Icon } from '../../shared/icon';
import { Empty, ErrorState } from '../../shared/states';

const AREAS = [
  {
    key: 'executive',
    label: 'Executive',
    icon: 'target',
    templates: ['ceo', 'board', 'monthly_management'],
    blurb: 'Overall performance for leadership',
  },
  {
    key: 'operations',
    label: 'Operations',
    icon: 'flow',
    templates: ['coo', 'operations'],
    blurb: 'Throughput, SLA, capacity',
  },
  {
    key: 'finance',
    label: 'Finance',
    icon: 'chart',
    templates: ['cfo', 'finance'],
    blurb: 'Revenue streams and markets',
  },
  {
    key: 'risk',
    label: 'Risk',
    icon: 'governance',
    templates: ['risk', 'compliance'],
    blurb: 'Risk indicators and refusals',
  },
  { key: 'custom', label: 'Custom', icon: 'ai', templates: [], blurb: 'Describe it to the AI analyst' },
];
const STAGES = ['Analysing', 'Designing', 'Writing', 'Validating', 'Finalising'];

@Component({
  selector: 'app-reports',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Empty, ErrorState],
  templateUrl: './reports.html',
  styleUrl: './reports.scss',
})
export class Reports implements OnInit {
  private api = inject(Api);
  private router = inject(Router);
  readonly auth = inject(Auth);
  readonly areas = AREAS;
  readonly stages = STAGES;
  readonly periods = [
    { key: 'last_month', label: 'Last month' },
    { key: 'last_quarter', label: 'Last quarter' },
    { key: 'year_to_date', label: 'Year to date' },
    { key: 'last_30_days', label: 'Last 30 days' },
  ];
  readonly items = signal<ReportSummary[]>([]);
  readonly loaded = signal(false);
  readonly templates = signal<ReportTemplate[]>([]);
  readonly status = signal('all');
  readonly wizard = signal(false);
  readonly area = signal<(typeof AREAS)[number] | null>(null);
  readonly template = signal<string | null>(null);
  readonly period = signal('last_month');
  readonly stage = signal<number | null>(null);
  readonly error = signal<string | null>(null);
  readonly filtered = computed(() => this.items().filter((r) => this.status() === 'all' || r.status === this.status()));
  ago = ago;

  async ngOnInit() {
    try {
      const [r, t] = await Promise.all([
        this.api.get<Envelope<ReportSummary[]>>('/reports'),
        this.api.get<Envelope<ReportTemplate[]>>('/report-templates'),
      ]);
      this.items.set(r.data);
      this.templates.set(t.data);
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.loaded.set(true);
    }
  }
  templatesFor() {
    const a = this.area();
    return this.templates().filter(
      (t) => a?.templates.includes(t.key) || (a?.key === 'executive' && t.organisation_id),
    );
  }
  custom() {
    this.router.navigate(['/ai'], { queryParams: { q: 'Create a monthly CEO report for student applications' } });
  }

  async generate() {
    this.error.set(null);
    this.stage.set(0);
    const tick = setInterval(() => this.stage.update((s) => (s !== null && s < STAGES.length - 1 ? s + 1 : s)), 900);
    try {
      const body = { template: this.template(), range: this.period() };
      const r = (await this.api.post<Envelope<ReportSummary>>('/reports/generate', body)).data;
      this.stage.set(STAGES.length);
      this.router.navigate(['/reports', r.id]);
    } catch (e) {
      this.error.set(errorMessage(e));
      this.stage.set(null);
    } finally {
      clearInterval(tick);
    }
  }
}
