import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ago, compact } from '../../core/format';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';

@Component({
  selector: 'app-dataset',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, RouterLink, Working, ErrorState],
  template: `
  <div class="page">
    @if (error()) { <app-error [message]="error()!" (retry)="load()"/> }
    @if (ds(); as d) {
      <header class="page-head"><div><a routerLink="/data" class="muted back">← Data</a><div class="eyebrow signal">Dataset</div><h1>{{ d.label }}</h1>
        <p class="lede">{{ d.description ?? 'Profiled automatically on ingestion.' }} <span class="mono muted">{{ d.physical_schema }}.{{ d.physical_table }}</span></p></div>
        <div class="row">@if (auth.can('data.manage')) { <button class="btn" (click)="profile()" [disabled]="busy()"><app-icon name="refresh" [size]="16"/> Re-profile</button> }
          @if (auth.can('semantic.manage')) { <button class="btn signal" (click)="propose()"><app-icon name="semantic" [size]="16"/> Generate semantic model</button> }</div></header>

      <section class="stats panel">
        <span>Rows<b>{{ compact(d.row_count ?? 0) }}</b></span><span>Columns<b>{{ d.fields.length }}</b></span><span>Latest record<b>{{ ago(d.freshness_at) }}</b></span>
        <span>Profiled<b>{{ ago(d.profile?.profiled_at) }}</b></span><span>Issues<b [class.neg]="d.profile?.issues?.length">{{ d.profile?.issues?.length ?? 0 }}</b></span>
      </section>

      @if (proposal(); as p) {
        <section class="panel proposal rise">
          <div class="panel-head"><div><div class="eyebrow ai">AI-proposed semantic model</div><h2>{{ p.name }}</h2></div>
            <div class="row"><button class="btn ghost" (click)="proposal.set(null)">Discard</button><button class="btn signal" (click)="createModel()" [disabled]="busy()">Publish model</button></div></div>
          <div class="cols">
            <div><div class="eyebrow">Time dimension</div><p><b>{{ p.time_dimension ?? 'none found' }}</b></p>
              <div class="eyebrow" style="margin-top:14px">Dimensions</div><div class="chips">@for (x of p.dimensions; track x.key) { <span class="chip">{{ x.label }}</span> }</div></div>
            <div><div class="eyebrow">Metrics</div><ul>@for (m of p.metrics; track m.key) { <li><b>{{ m.label }}</b> <span class="muted">= {{ m.expression }} · {{ m.format }}</span></li> }</ul></div>
            <div><div class="eyebrow">Why</div><ul class="muted small">@for (r of p.reasons; track r) { <li>{{ r }}</li> }</ul></div>
          </div>
        </section>
      }
      @if (created()) { <p class="ok panel"><app-icon name="check" [size]="16"/> Model “{{ created() }}” is live. <a class="link" routerLink="/explore" [queryParams]="{ metric: created() + '.records' }">Explore it →</a> or ask the AI analyst about it.</p> }

      <section class="panel"><div class="panel-head"><h3>Columns &amp; profile</h3></div>
        <table class="table"><thead><tr><th>Column</th><th>Type</th><th class="r">Nulls</th><th class="r">Distinct</th><th>Range / top values</th><th></th></tr></thead>
          <tbody>@for (f of d.fields; track f.id) {
            <tr><td><b>{{ f.label }}</b><div class="mono muted small">{{ f.name }}</div></td><td><span class="badge">{{ f.data_type }}</span></td>
              <td class="r">@if (f.profile?.redacted) { — } @else { {{ f.profile?.null_pct ?? 0 }}% }</td><td class="r">{{ f.profile?.distinct ?? '—' }}</td>
              <td class="small">@if (f.profile?.redacted) { <span class="muted">Restricted</span> } @else if (f.profile?.top_values) { {{ top(f.profile.top_values) }} } @else if (f.profile?.min) { {{ f.profile.min }} → {{ f.profile.max }} }</td>
              <td>@if (f.is_sensitive) { <span class="badge critical"><app-icon name="lock" [size]="11"/> sensitive</span> }@if (f.profile?.outliers_4sd) { <span class="badge warning">{{ f.profile.outliers_4sd }} outliers</span> }</td></tr>
          }</tbody></table></section>

      <h3 style="margin:24px 0 12px">Preview</h3>
      @if (preview(); as pv) {
        <section class="panel scroll-x">
          @if (pv.masked) { <p class="muted small" style="padding:12px 16px 0"><app-icon name="lock" [size]="12"/> Sensitive columns are masked for your role.</p> }
          <table class="table"><thead><tr>@for (c of pv.columns; track c) { <th>{{ c }}</th> }</tr></thead>
            <tbody>@for (r of pv.rows; track $index) { <tr>@for (c of pv.columns; track c) { <td>{{ r[c] }}</td> }</tr> }</tbody></table></section>
      } @else { <app-working title="Sampling rows" [steps]="['Applying column security', 'Reading 50 rows']"/> }
    }
  </div>`,
  styles: [`.back{font-size:12.5px;display:block;width:max-content;margin-bottom:10px}.stats{display:grid;grid-template-columns:repeat(5,1fr);padding:18px;margin-bottom:20px}
    .stats span{display:grid;font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.08em}.stats b{font-size:24px;color:var(--ink-1);letter-spacing:-.02em;text-transform:none}.neg{color:var(--neg)!important}
    .proposal{margin-bottom:20px;border-color:color-mix(in srgb,var(--ai) 35%,transparent)}.cols{display:grid;grid-template-columns:1fr 1.2fr 1fr;gap:24px;padding:16px 22px 22px}.cols ul{margin:8px 0;padding-left:18px;display:grid;gap:4px}
    .chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.chips .chip{cursor:default}.small{font-size:12px}.ok{display:flex;gap:8px;align-items:center;padding:14px 18px;margin-bottom:20px;color:var(--pos)}.link{color:var(--ai)}
    section.panel{margin-bottom:16px}@media(max-width:960px){.cols{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,1fr);gap:12px}}`],
})
export class DatasetPage implements OnInit {
  private api = inject(Api);
  private route = inject(ActivatedRoute);
  readonly auth = inject(Auth);
  readonly id = input.required<string>();
  readonly ds = signal<any | null>(null);
  readonly preview = signal<any | null>(null);
  readonly proposal = signal<any | null>(null);
  readonly created = signal<string | null>(null);
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);
  ago = ago; compact = compact;

  async ngOnInit() {
    await this.load();
    if (this.route.snapshot.queryParamMap.get('new') && this.auth.can('semantic.manage')) this.propose();
  }
  async load() {
    try {
      this.ds.set((await this.api.get(`/datasets/${this.id()}`)).data);
      this.preview.set(await this.api.get(`/datasets/${this.id()}/preview`));
    } catch (e) { this.error.set(errorMessage(e)); }
  }
  async profile() { this.busy.set(true); try { this.ds.set((await this.api.post(`/datasets/${this.id()}/profile`)).data); } finally { this.busy.set(false); } }
  async propose() { this.proposal.set((await this.api.get(`/datasets/${this.id()}/semantic-proposal`)).data); }
  async createModel() {
    this.busy.set(true);
    try { const r = (await this.api.post(`/datasets/${this.id()}/semantic-model`, { definition: this.proposal() })).data; this.created.set(r.key); this.proposal.set(null); }
    catch (e) { this.error.set(errorMessage(e)); } finally { this.busy.set(false); }
  }
  top(t: any[]) { return t.slice(0, 4).map(x => `${x.value} (${compact(x.count)})`).join(' · '); }
}
