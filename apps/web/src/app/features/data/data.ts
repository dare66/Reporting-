import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ago, compact } from '../../core/format';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';
import { ConnectorMark } from './connector-mark';

@Component({
  selector: 'app-data',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, RouterLink, FormsModule, ConnectorMark, Working, ErrorState],
  template: `
  <div class="page">
    <header class="page-head"><div><div class="eyebrow signal">Data platform</div><h1>Connect, understand, model</h1>
      <p class="lede">Bring data in; AIXBI profiles it, proposes a semantic model, and makes it askable — under the same governance as everything else.</p></div></header>

    @if (auth.can('data.manage')) {
      <section class="journey panel">
        <ol>@for (s of journey; track s; let i = $index) { <li [class.on]="i <= step()"><span>{{ i + 1 }}</span>{{ s }}</li> }</ol>
        <label class="drop" [class.over]="over()" (dragover)="$event.preventDefault(); over.set(true)" (dragleave)="over.set(false)" (drop)="$event.preventDefault(); over.set(false); upload($event.dataTransfer?.files?.[0])">
          <input type="file" accept=".csv,.xlsx,.xls,.json" (change)="upload($any($event.target).files?.[0])" hidden>
          <app-icon name="upload" [size]="22"/><b>Drop a CSV, Excel or JSON file</b><span class="muted">or click to browse · up to 50 MB</span>
        </label>
        @if (uploading()) { <app-working title="Ingesting your data" [steps]="['Reading file', 'Inferring types', 'Loading analytical table', 'Profiling quality']"/> }
        @if (uploadError()) { <app-error title="We couldn't ingest this file" [message]="uploadError()!" (retry)="uploadError.set(null)"/> }
      </section>
    }

    <h3 class="sec-title">Connected sources</h3>
    <div class="sources">
      @for (s of sources(); track s.id) {
        <div class="src panel">
          <div class="row"><app-connector-mark [key]="s.connector_key" [size]="36"/><div class="grow"><b>{{ s.name }}</b><div class="muted small">{{ s.connector_key }} · {{ s.sync_mode }} · {{ s.datasets_count }} datasets</div></div>
            <span class="badge" [class.good]="s.status === 'connected'" [class.critical]="s.status === 'error'">{{ s.status }}</span></div>
          <div class="stats">
            <span>Last sync<b>{{ ago(s.last_sync_at) }}</b></span><span>Records<b>{{ s.last_run ? compact(s.last_run.records) : '—' }}</b></span>
            <span>Duration<b>{{ s.last_run?.duration_ms ? (s.last_run.duration_ms / 1000).toFixed(1) + 's' : '—' }}</b></span>
            <span>Errors<b [class.neg]="s.last_run?.error_count">{{ s.last_run?.error_count ?? 0 }}</b></span><span>Warnings<b>{{ s.last_run?.warning_count ?? 0 }}</b></span>
          </div>
          @if (s.last_error) { <p class="neg small">{{ s.last_error }}</p> }
          @if (auth.can('data.manage') && !['csv','excel','json'].includes(s.connector_key)) {
            <div class="row"><button class="btn sm" (click)="test(s)">Test connection</button>@if (testResult()[s.id]; as t) { <span class="small" [class.neg]="!t.ok">{{ t.message }}</span> }</div>
          }
        </div>
      }
    </div>

    <h3 class="sec-title">Datasets</h3>
    <section class="panel"><table class="table">
      <thead><tr><th>Dataset</th><th>Source</th><th class="r">Rows</th><th class="r">Columns</th><th>Freshness</th><th>Quality</th><th></th></tr></thead>
      <tbody>@for (d of datasets(); track d.id) {
        <tr><td><b>{{ d.label }}</b><div class="muted small mono">{{ d.physical_schema }}.{{ d.physical_table }}</div></td><td>{{ d.data_source?.name }}</td>
          <td class="r">{{ compact(d.row_count ?? 0) }}</td><td class="r">{{ d.fields_count }}</td><td>{{ ago(d.freshness_at) }}</td>
          <td>@if (d.profile?.issues?.length) { <span class="badge warning">{{ d.profile.issues.length }} issues</span> } @else { <span class="badge good">✓ clean</span> }</td>
          <td class="r"><a class="btn sm ghost" [routerLink]="['/data/datasets', d.id]">Open →</a></td></tr>
      }</tbody></table></section>

    <h3 class="sec-title">Connector library</h3>
    <div class="connectors">
      @for (c of connectors(); track c.key) {
        <button class="conn panel" [class.planned]="c.status !== 'available'" (click)="pickConnector(c)" [disabled]="c.status !== 'available' || !auth.can('data.manage')">
          <app-connector-mark [key]="c.key"/><b>{{ c.name }}</b><span class="muted small">{{ c.category }} · {{ c.capabilities.join(', ').replaceAll('_', ' ') }}</span>
          @if (c.status !== 'available') { <span class="badge">On the roadmap</span> }
        </button>
      }
    </div>

    @if (connector(); as c) {
      <div class="scrim" (click)="closeConnector()"></div>
      <form class="modal panel fade-in" (submit)="$event.preventDefault(); createSource()">
        <div class="row"><app-connector-mark [key]="c.key"/><h3>Connect {{ c.name }}</h3></div>
        @if (['csv', 'excel', 'json'].includes(c.key)) { <p class="muted">Use the upload area above for files.</p> }
        @else if (ingest(); as i) {
          <p>Send JSON records to this URL. It contains a secret token and <b>will not be shown again</b>.</p>
          <div class="row"><input class="input grow mono" [value]="i.method + ' ' + i.url" readonly aria-label="Webhook URL" (focus)="$any($event.target).select()"><button type="button" class="btn" (click)="copyIngestUrl(i.url)">{{ copied() ? 'Copied' : 'Copy' }}</button></div>
          <p class="muted small">Body: one object or an array of objects. Records are appended to the dataset “{{ form.name }}”.</p>
          <div class="row"><span class="spacer"></span><button type="button" class="btn signal" (click)="closeConnector()">Done</button></div>
        }
        @else {
          <label class="field">Name<input class="input" [(ngModel)]="form.name" name="name" required></label>
          @for (f of c.config_schema; track f.key) {
            <label class="field">{{ f.key }}{{ f.required ? ' *' : '' }}<input class="input" [type]="f.type === 'secret' ? 'password' : f.type === 'integer' ? 'number' : 'text'" [(ngModel)]="form.config[f.key]" [name]="f.key" [required]="f.required"></label>
          }
          <p class="muted small">Credentials are encrypted at rest (AES-256) and never shown again. Connections run read-only.</p>
          @if (formError()) { <p class="neg small">{{ formError() }}</p> }
          <div class="row"><span class="spacer"></span><button type="button" class="btn ghost" (click)="closeConnector()">Cancel</button><button class="btn signal">Connect &amp; test</button></div>
        }
      </form>
    }
  </div>`,
  styles: [`.journey{padding:20px;margin-bottom:28px;display:grid;gap:16px}
    .journey ol{list-style:none;display:flex;gap:6px;padding:0;margin:0;flex-wrap:wrap}.journey li{display:flex;gap:8px;align-items:center;padding:6px 12px 6px 6px;border-radius:999px;background:var(--bg-3);font-size:12.5px;color:var(--ink-3)}
    .journey li span{width:20px;height:20px;border-radius:50%;display:grid;place-items:center;background:var(--bg-1);font-size:11px;font-weight:700}.journey li.on{color:var(--ink-1)}.journey li.on span{background:var(--accent);color:#140d02}
    .drop{display:grid;justify-items:center;gap:6px;padding:28px;border:1.5px dashed var(--line-2);border-radius:var(--r-lg);cursor:pointer;transition:all .2s}.drop:hover,.drop.over{border-color:var(--accent);background:var(--accent-soft)}.drop app-icon{color:var(--accent)}
    .sec-title{margin:28px 0 12px}.sources{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:14px}
    .src{padding:16px;display:grid;gap:12px}.grow{flex:1;min-width:0}.small{font-size:12px}.neg{color:var(--neg)}
    .stats{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.stats span{display:grid;font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.06em}.stats b{font-size:14px;color:var(--ink-1);text-transform:none;letter-spacing:0}
    .connectors{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
    .conn{display:grid;gap:8px;justify-items:start;padding:16px;text-align:left;cursor:pointer;color:inherit;transition:transform .2s var(--ease)}.conn:not(:disabled):hover{transform:translateY(-2px);border-color:var(--line-2)}
    .conn.planned{opacity:.6;cursor:default}
    .scrim{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:60}.modal{position:fixed;z-index:61;top:50%;left:50%;transform:translate(-50%,-50%);width:min(460px,calc(100vw - 24px));max-height:90vh;overflow:auto;padding:22px;display:grid;gap:12px}`],
})
export class DataPage implements OnInit {
  private api = inject(Api);
  private router = inject(Router);
  readonly auth = inject(Auth);
  readonly journey = ['Connect data', 'AI analyses it', 'Semantic model', 'Metrics', 'Ask & visualise'];
  readonly step = signal(0);
  readonly connectors = signal<any[]>([]);
  readonly sources = signal<any[]>([]);
  readonly datasets = signal<any[]>([]);
  readonly over = signal(false);
  readonly uploading = signal(false);
  readonly uploadError = signal<string | null>(null);
  readonly testResult = signal<Record<string, any>>({});
  readonly connector = signal<any | null>(null);
  readonly formError = signal<string | null>(null);
  readonly ingest = signal<{ url: string; method: string } | null>(null);
  readonly copied = signal(false);
  form: any = { name: '', config: {} };
  ago = ago; compact = compact;

  ngOnInit() { this.load(); }
  async load() {
    const [c, s, d] = await Promise.all([this.api.get('/connectors'), this.api.get('/data-sources'), this.api.get('/datasets')]);
    this.connectors.set(c.data); this.sources.set(s.data); this.datasets.set(d.data);
  }
  async upload(file?: File | null) {
    if (!file) return;
    this.uploading.set(true); this.uploadError.set(null); this.step.set(1);
    const form = new FormData();
    form.append('file', file);
    try {
      const r = (await this.api.upload('/data/upload', form)).data;
      this.step.set(2);
      this.router.navigate(['/data/datasets', r.dataset.id], { queryParams: { new: 1 } });
    } catch (e) { this.uploadError.set(errorMessage(e)); this.step.set(0); } finally { this.uploading.set(false); }
  }
  async test(s: any) {
    const r = (await this.api.post(`/data-sources/${s.id}/test`)).data;
    this.testResult.update(t => ({ ...t, [s.id]: r }));
    this.load();
  }
  pickConnector(c: any) { this.form = { name: c.name + ' source', config: {} }; this.formError.set(null); this.ingest.set(null); this.connector.set(c); }
  closeConnector() { this.connector.set(null); this.ingest.set(null); this.copied.set(false); }
  async createSource() {
    try {
      const res = await this.api.post('/data-sources', { connector_key: this.connector().key, name: this.form.name, config: this.form.config });
      if (res.ingest) { this.ingest.set(res.ingest); this.load(); return; }
      const t = (await this.api.post(`/data-sources/${res.data.id}/test`)).data;
      if (!t.ok) { this.formError.set(t.message); return; }
      this.closeConnector();
      this.load();
    } catch (e) { this.formError.set(errorMessage(e)); }
  }
  async copyIngestUrl(url: string) {
    await navigator.clipboard.writeText(url);
    this.copied.set(true);
  }
}
