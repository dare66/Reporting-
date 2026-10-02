import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Dataset, DatasetPreview, Envelope, FieldProfile, SemanticProposal } from '../../core/models';
import { Auth } from '../../core/auth.service';
import { ago, compact } from '../../core/format';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';

@Component({
  selector: 'app-dataset',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, RouterLink, Working, ErrorState],
  templateUrl: './dataset.html',
  styleUrl: './dataset.scss',
})
export class DatasetPage implements OnInit {
  private api = inject(Api);
  private route = inject(ActivatedRoute);
  readonly auth = inject(Auth);
  readonly id = input.required<string>();
  readonly ds = signal<Dataset | null>(null);
  readonly preview = signal<DatasetPreview | null>(null);
  readonly proposal = signal<SemanticProposal | null>(null);
  readonly created = signal<string | null>(null);
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);
  ago = ago;
  compact = compact;

  async ngOnInit() {
    await this.load();
    if (this.route.snapshot.queryParamMap.get('new') && this.auth.can('semantic.manage')) this.propose();
  }
  async load() {
    try {
      this.ds.set((await this.api.get<Envelope<Dataset>>(`/datasets/${this.id()}`)).data);
      this.preview.set(await this.api.get<DatasetPreview>(`/datasets/${this.id()}/preview`));
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
  async profile() {
    this.busy.set(true);
    try {
      this.ds.set((await this.api.post<Envelope<Dataset>>(`/datasets/${this.id()}/profile`)).data);
    } finally {
      this.busy.set(false);
    }
  }
  async propose() {
    this.proposal.set(
      (await this.api.get<Envelope<SemanticProposal>>(`/datasets/${this.id()}/semantic-proposal`)).data,
    );
  }
  async createModel() {
    this.busy.set(true);
    try {
      const body = { definition: this.proposal() };
      const r = (await this.api.post<Envelope<{ key: string }>>(`/datasets/${this.id()}/semantic-model`, body)).data;
      this.created.set(r.key);
      this.proposal.set(null);
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }
  top(t: NonNullable<FieldProfile['top_values']>) {
    return t
      .slice(0, 4)
      .map((x) => `${x.value} (${compact(x.count)})`)
      .join(' · ');
  }
}
