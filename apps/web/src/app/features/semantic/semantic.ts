import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ago, fmt } from '../../core/format';
import { Icon } from '../../shared/icon';
import { ErrorState } from '../../shared/states';

@Component({
  selector: 'app-semantic',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, RouterLink, ErrorState],
  template: `
  <div class="page">
    <header class="page-head"><div><div class="eyebrow signal">Semantic layer</div><h1>One definition of every number</h1>
      <p class="lede">Metrics, dimensions and relationships defined once, secured once, and reused by dashboards, reports and the AI analyst.</p></div></header>
    @if (error()) { <app-error [message]="error()!" (retry)="load()"/> }
    <div class="models">@for (m of models(); track m.key) {
      <button class="model panel" [class.on]="sel() === m.key" (click)="sel.set(m.key); metric.set(null)">
        <span class="eyebrow">{{ m.domain }}</span><b>{{ m.name }}</b><span class="muted small">{{ m.description }}</span>
        <span class="row small muted"><span>{{ m.metrics_count }} metrics</span>·<span>{{ m.dimensions_count }} dimensions</span>·<span>v{{ m.version }}</span></span>
      </button> }</div>

    @if (model(); as m) {
      <div class="cols">
        <section class="panel">
          <div class="panel-head"><h3>Metrics</h3></div>
          <ul class="list">@for (x of m.metrics; track x.key) {
            <li><button [class.on]="metric()?.key === x.key" (click)="openMetric(x)"><span><b>{{ x.label }}</b>@if (x.is_kpi) { <span class="badge">KPI</span> }</span>
              <small class="muted">{{ x.description }}</small>@if (x.expression) { <code class="mono">{{ x.expression }}</code> }</button></li> }</ul>
        </section>
        <section class="panel">
          @if (metric(); as x) {
            <div class="panel-head"><div><div class="eyebrow">{{ m.name }}</div><h2>{{ x.label }}</h2></div>
              <a class="btn sm" routerLink="/explore" [queryParams]="{ metric: x.ref }">Explore →</a></div>
            <div class="detail">
              @if (auth.can('semantic.manage')) {
                <label class="field">Label<input class="input" [(ngModel)]="edit.label"></label>
                <label class="field">Description<textarea class="input" rows="2" [(ngModel)]="edit.description"></textarea></label>
                <div class="row"><label class="field grow">Target<input class="input" type="number" step="any" [(ngModel)]="edit.target"></label>
                  <label class="field grow">Owner<input class="input" [(ngModel)]="edit.owner"></label></div>
                <label class="field">Synonyms (how people ask for it — comma separated)<input class="input" [(ngModel)]="edit.synonyms"></label>
                <div class="row"><label class="row small"><input type="checkbox" [(ngModel)]="edit.is_kpi"> Headline KPI</label><label class="row small"><input type="checkbox" [(ngModel)]="edit.higher_is_better"> Higher is better</label>
                  <span class="spacer"></span>@if (saved()) { <span class="small ok">✓ saved</span> }<button class="btn signal" (click)="save(x)">Save</button></div>
              } @else {
                <p>{{ x.description }}</p><p class="muted small">Synonyms: {{ x.synonyms.join(', ') }}</p>
              }
              <div class="eyebrow" style="margin-top:12px">Lineage</div>
              @if (lineage(); as l) {
                <div class="lineage">
                  <div class="node"><small>Source</small><b>{{ l.source.name ?? '—' }}</b><span class="muted">{{ l.source.connector }} · synced {{ ago(l.source.last_sync_at) }}</span></div>
                  <div class="node"><small>Table</small><b class="mono">{{ l.table.name }}</b><span class="muted">{{ fmt(l.table.row_count) }} rows</span></div>
                  @for (t of l.transformations; track $index) { <div class="node"><small>Field → transformation</small><b class="mono">{{ t.field.split('.').at(-1) }}</b><span class="mono muted">{{ t.transformation }}</span></div> }
                  <div class="node metric"><small>Metric</small><b>{{ l.metric.label }}</b><span class="mono muted">{{ l.metric.expression }}</span></div>
                  <div class="node"><small>Used in</small>
                    @for (d of l.dashboards; track d.id) { <a [routerLink]="['/dashboards', d.id]">▦ {{ d.title }}</a> }
                    @for (r of l.reports; track r.id) { <a [routerLink]="['/reports', r.id]">▤ {{ r.title }}</a> }
                    @if (!l.dashboards.length && !l.reports.length) { <span class="muted">Not yet used</span> }</div>
                </div>
              }
            </div>
          } @else {
            <div class="panel-head"><h3>Dimensions</h3></div>
            <ul class="list dims">@for (d of m.dimensions; track d.key) {
              <li><div><b>{{ d.label }}</b> <span class="badge">{{ d.type }}</span>@if (d.is_sensitive) { <span class="badge critical"><app-icon name="lock" [size]="11"/> sensitive</span> }
                <div class="muted small">{{ d.dataset }}{{ d.field ? '.' + d.field : '' }} · {{ d.synonyms.join(', ') }}</div></div></li> }</ul>
            @if (m.hierarchies.length) { <div class="panel-head"><h3>Hierarchies (drill paths)</h3></div>
              <ul class="list dims">@for (h of m.hierarchies; track h.key) { <li><b>{{ h.label }}</b><span class="muted"> {{ h.levels.join(' → ') }}</span></li> }</ul> }
          }
        </section>
      </div>
    }
  </div>`,
  styles: [`.models{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin-bottom:20px}
    .model{display:grid;gap:4px;text-align:left;padding:16px;cursor:pointer;color:inherit}.model.on{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
    .cols{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:20px;align-items:start}
    .list{list-style:none;margin:0;padding:8px}.list button{display:grid;gap:3px;width:100%;text-align:left;padding:10px 12px;border:0;background:none;border-radius:var(--r-md);cursor:pointer;color:inherit}
    .list button:hover,.list button.on{background:var(--bg-3)}code{font-size:11px;color:var(--ink-3)}.dims li{padding:10px 12px;border-bottom:1px solid var(--line)}
    .detail{padding:12px 20px 20px;display:grid;gap:12px}.grow{flex:1}.small{font-size:12px}.ok{color:var(--pos)}
    .lineage{display:grid;gap:0}.node{display:grid;gap:2px;padding:10px 14px;border-left:2px solid var(--line-2);margin-left:6px;position:relative}
    .node::before{content:'';position:absolute;left:-6px;top:14px;width:10px;height:10px;border-radius:50%;background:var(--bg-1);border:2px solid var(--ink-3)}
    .node.metric::before{border-color:var(--accent);background:var(--accent)}.node small{font-size:10.5px;text-transform:uppercase;letter-spacing:.1em;color:var(--ink-3)}.node a{color:var(--ai);font-size:13px}
    @media(max-width:960px){.cols{grid-template-columns:1fr}}`],
})
export class Semantic implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly models = signal<any[]>([]);
  readonly catalog = signal<any[]>([]);
  readonly sel = signal<string>('applications');
  readonly metric = signal<any | null>(null);
  readonly lineage = signal<any | null>(null);
  readonly saved = signal(false);
  readonly error = signal<string | null>(null);
  readonly model = computed(() => this.catalog().find(m => m.key === this.sel()));
  edit: any = {};
  ago = ago; fmt = (v: number) => fmt(v);

  ngOnInit() { this.load(); }
  async load() {
    try { const [m, c] = await Promise.all([this.api.get('/semantic-models'), this.api.get('/semantic-catalog')]); this.models.set(m.data); this.catalog.set(c.data); }
    catch (e) { this.error.set(errorMessage(e)); }
  }
  async openMetric(x: any) {
    this.metric.set(x);
    this.saved.set(false);
    this.edit = { label: x.label, description: x.description, target: x.target, owner: x.owner, synonyms: x.synonyms.join(', '), is_kpi: x.is_kpi, higher_is_better: x.higher_is_better };
    this.lineage.set(null);
    this.lineage.set((await this.api.get(`/semantic-models/${this.sel()}/metrics/${x.key}/lineage`)).data);
  }
  async save(x: any) {
    const body = { ...this.edit, target: this.edit.target === '' || this.edit.target === null ? null : +this.edit.target, synonyms: String(this.edit.synonyms).split(',').map((s: string) => s.trim()).filter(Boolean) };
    await this.api.patch(`/semantic-models/${this.sel()}/metrics/${x.key}`, body);
    this.saved.set(true);
    await this.load();
  }
}
