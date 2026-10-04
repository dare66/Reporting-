import { CdkTrapFocus } from '@angular/cdk/a11y';
import { ChangeDetectionStrategy, Component, ElementRef, OnInit, inject, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { ConnectionTest, Connector, DataSource, DatasetSummary, Envelope, UploadResult } from '../../core/models';

/** Creating a webhook source also returns its ingest URL, shown exactly once. */
interface CreatedSource extends Envelope<DataSource> {
  ingest: { url: string; method: string } | null;
}
import { Auth } from '../../core/auth.service';
import { ago, compact, fmtDuration } from '../../core/format';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';
import { ConnectorMark } from './connector-mark';
import { Scrim } from '../../shared/scrim';

@Component({
  selector: 'app-data',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CdkTrapFocus, Scrim, Icon, RouterLink, FormsModule, ConnectorMark, Working, ErrorState],
  templateUrl: './data.html',
  styleUrl: './data.scss',
})
export class DataPage implements OnInit {
  private api = inject(Api);
  private router = inject(Router);
  readonly auth = inject(Auth);
  readonly journey = ['Connect data', 'AIXBI understands it', 'Approve KPIs', 'Dashboard & report', 'Ask & act'];
  readonly step = signal(0);
  readonly connectors = signal<Connector[]>([]);
  readonly sources = signal<DataSource[]>([]);
  readonly datasets = signal<DatasetSummary[]>([]);
  readonly over = signal(false);
  readonly uploading = signal(false);
  readonly uploadError = signal<string | null>(null);
  readonly testResult = signal<Record<string, ConnectionTest>>({});
  readonly connector = signal<Connector | null>(null);
  readonly formError = signal<string | null>(null);
  readonly ingest = signal<{ url: string; method: string } | null>(null);
  readonly copied = signal(false);
  private ingestUrl = viewChild<ElementRef<HTMLInputElement>>('ingestUrl');
  form: { name: string; config: Record<string, string> } = { name: '', config: {} };
  ago = ago;
  duration = fmtDuration;
  compact = compact;

  ngOnInit() {
    this.load();
  }
  async load() {
    const [c, s, d] = await Promise.all([
      this.api.get<Envelope<Connector[]>>('/connectors'),
      this.api.get<Envelope<DataSource[]>>('/data-sources'),
      this.api.get<Envelope<DatasetSummary[]>>('/datasets'),
    ]);
    this.connectors.set(c.data);
    this.sources.set(s.data);
    this.datasets.set(d.data);
  }
  async upload(file?: File | null) {
    if (!file) return;
    this.uploading.set(true);
    this.uploadError.set(null);
    this.step.set(1);
    const form = new FormData();
    form.append('file', file);
    try {
      const r = (await this.api.upload<Envelope<UploadResult>>('/data/upload', form)).data;
      this.step.set(2);
      // Straight into Auto BI: understand the file, propose KPIs, design the dashboard and report.
      this.router.navigate(['/data/sources', r.source.id, 'auto-bi']);
    } catch (e) {
      this.uploadError.set(errorMessage(e));
      this.step.set(0);
    } finally {
      this.uploading.set(false);
    }
  }
  async test(s: DataSource) {
    const r = (await this.api.post<Envelope<ConnectionTest>>(`/data-sources/${s.id}/test`)).data;
    this.testResult.update((t) => ({ ...t, [s.id]: r }));
    this.load();
  }
  pickConnector(c: Connector) {
    this.form = { name: c.name + ' source', config: {} };
    this.formError.set(null);
    this.ingest.set(null);
    this.connector.set(c);
  }
  closeConnector() {
    this.connector.set(null);
    this.ingest.set(null);
    this.copied.set(false);
  }
  async createSource() {
    const connector = this.connector();
    if (!connector) return;
    try {
      const res = await this.api.post<CreatedSource>('/data-sources', {
        connector_key: connector.key,
        name: this.form.name,
        config: this.form.config,
      });
      if (res.ingest) {
        this.ingest.set(res.ingest);
        // The form that had focus is replaced by the URL: put focus on it, ready to copy.
        setTimeout(() => this.ingestUrl()?.nativeElement.select());
        this.load();
        return;
      }
      const t = (await this.api.post<Envelope<ConnectionTest>>(`/data-sources/${res.data.id}/test`)).data;
      if (!t.ok) {
        this.formError.set(t.message);
        return;
      }
      this.closeConnector();
      this.load();
    } catch (e) {
      this.formError.set(errorMessage(e));
    }
  }
  async copyIngestUrl(url: string) {
    await navigator.clipboard.writeText(url);
    this.copied.set(true);
  }
}
