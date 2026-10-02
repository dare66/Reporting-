import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { CatalogMetric, CatalogModel, Envelope, MetricLineage, SemanticModelSummary } from '../../core/models';

/** Editable metric definition; numbers and lists are edited as text. */
interface MetricForm {
  label: string;
  description: string;
  target: string;
  owner: string;
  synonyms: string;
  is_kpi: boolean;
  higher_is_better: boolean;
}
import { Auth } from '../../core/auth.service';
import { ago, fmt } from '../../core/format';
import { Icon } from '../../shared/icon';
import { ErrorState } from '../../shared/states';

@Component({
  selector: 'app-semantic',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, RouterLink, ErrorState],
  templateUrl: './semantic.html',
  styleUrl: './semantic.scss',
})
export class Semantic implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly models = signal<SemanticModelSummary[]>([]);
  readonly catalog = signal<CatalogModel[]>([]);
  readonly sel = signal<string>('applications');
  readonly metric = signal<CatalogMetric | null>(null);
  readonly lineage = signal<MetricLineage | null>(null);
  readonly saved = signal(false);
  readonly error = signal<string | null>(null);
  readonly model = computed(() => this.catalog().find((m) => m.key === this.sel()));
  edit: MetricForm = {
    label: '',
    description: '',
    target: '',
    owner: '',
    synonyms: '',
    is_kpi: false,
    higher_is_better: true,
  };
  ago = ago;
  fmt = (v: number | null) => fmt(v);

  ngOnInit() {
    this.load();
  }
  async load() {
    try {
      const [m, c] = await Promise.all([
        this.api.get<Envelope<SemanticModelSummary[]>>('/semantic-models'),
        this.api.get<Envelope<CatalogModel[]>>('/semantic-catalog'),
      ]);
      this.models.set(m.data);
      this.catalog.set(c.data);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
  async openMetric(x: CatalogMetric) {
    this.metric.set(x);
    this.saved.set(false);
    this.edit = {
      label: x.label,
      description: x.description ?? '',
      target: x.target === null ? '' : String(x.target),
      owner: x.owner ?? '',
      synonyms: x.synonyms.join(', '),
      is_kpi: x.is_kpi,
      higher_is_better: x.higher_is_better,
    };
    this.lineage.set(null);
    try {
      const url = `/semantic-models/${this.sel()}/metrics/${x.key}/lineage`;
      this.lineage.set((await this.api.get<Envelope<MetricLineage>>(url)).data);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
  async save(x: CatalogMetric) {
    const target = String(this.edit.target).trim();
    const body = {
      ...this.edit,
      target: target === '' ? null : Number(target),
      synonyms: this.edit.synonyms
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean),
    };
    try {
      await this.api.patch(`/semantic-models/${this.sel()}/metrics/${x.key}`, body);
      this.saved.set(true);
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
}
