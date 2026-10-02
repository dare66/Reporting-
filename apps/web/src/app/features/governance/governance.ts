import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../core/api.service';
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
  template: `
  <div class="page">
    <header class="page-head"><div><div class="eyebrow signal">Governance</div><h1>Trust, verified</h1>
      <p class="lede">AI usage and grounding, the complete audit trail, and data quality — so every insight can be defended.</p></div>
      <div class="seg">@for (t of tabs(); track t.key) { <button [class.on]="tab() === t.key" (click)="tab.set(t.key); load()">{{ t.label }}</button> }</div></header>
    @if (error()) { <app-error [message]="error()!" (retry)="load()"/> }

    @if (tab() === 'ai' && ai(); as a) {
      <section class="tiles panel">
        <span>AI runs (30d)<b>{{ a.totals.runs }}</b></span><span>Grounded in evidence<b>{{ a.totals.grounded_share === null ? '—' : (a.totals.grounded_share * 100).toFixed(0) + '%' }}</b></span>
        <span>Tokens in / out<b>{{ compact(a.totals.tokens_in) }} / {{ compact(a.totals.tokens_out) }}</b></span><span>Cost<b>\${{ a.totals.cost_usd.toFixed(2) }}</b></span>
        <span>Avg latency<b>{{ (a.totals.avg_latency_ms / 1000).toFixed(1) }}s</b></span><span>Feedback<b>👍 {{ a.totals.feedback_positive }} · 👎 {{ a.totals.feedback_negative }}</b></span>
      </section>
      <div class="cols">
        <section class="panel"><div class="panel-head"><h3>Runs per day</h3></div><div class="pad"><app-chart [spec]="runsSpec()" [height]="220"/></div></section>
        <section class="panel"><div class="panel-head"><h3>Intents</h3></div><div class="pad"><app-chart [spec]="intentSpec()" [height]="220"/></div></section>
      </div>
      <section class="panel"><div class="panel-head"><h3>Recent AI runs</h3></div>
        <table class="table"><thead><tr><th>Question</th><th>Intent</th><th>Planner</th><th>Status</th><th class="r">Evidence</th><th class="r">Tokens</th><th class="r">Latency</th><th>When</th></tr></thead>
          <tbody>@for (r of a.recent; track r.id) { <tr><td>{{ r.question }}</td><td>{{ r.intent }}</td><td><span class="badge" [class.ai]="r.planner === 'llm'">{{ r.planner?.split(' ')[0] }}</span></td>
            <td><span class="badge" [class.good]="r.status === 'succeeded'" [class.warning]="r.status !== 'succeeded'">{{ r.status }}</span></td><td class="r">{{ r.evidence.length }}</td>
            <td class="r">{{ r.tokens_in + r.tokens_out }}</td><td class="r">{{ r.latency_ms }} ms</td><td class="muted">{{ ago(r.created_at) }}</td></tr> }</tbody></table></section>
      <div class="cols">
        <section class="panel"><div class="panel-head"><h3>Model registry</h3></div><table class="table"><tbody>@for (m of a.models; track m.id) {
          <tr><td><b>{{ m.model }}</b><div class="muted small">{{ m.provider }} · {{ m.purpose }}</div></td><td class="r small">\${{ m.cost_per_mtok_in }} / \${{ m.cost_per_mtok_out }} per MTok</td><td>@if (m.is_default) { <span class="badge ai">default</span> }</td></tr> }</tbody></table></section>
        <section class="panel"><div class="panel-head"><h3>Agents &amp; prompts</h3></div><table class="table"><tbody>@for (g of a.agents; track g.key) {
          <tr><td><b>{{ g.name }}</b><div class="muted small">{{ g.description }}</div></td><td class="small muted">{{ g.stage }}</td></tr> }
          @for (p of a.prompts; track p.id) { <tr><td><b class="mono">{{ p.key }}</b> v{{ p.version }}</td><td>@if (p.is_active) { <span class="badge good">active</span> }</td></tr> }</tbody></table></section>
      </div>
    }

    @if (tab() === 'audit' && audit(); as log) {
      <div class="row wrap filters">
        <input class="input" style="max-width:240px" placeholder="Action prefix (e.g. query, auth)" [ngModel]="action()" (ngModelChange)="action.set($event)" (keyup.enter)="load()">
        <select class="select" style="max-width:160px" [ngModel]="decision()" (ngModelChange)="decision.set($event); load()"><option value="">All decisions</option><option value="allow">Allowed</option><option value="deny">Denied</option></select>
        <button class="btn" (click)="load()">Filter</button><span class="muted small">{{ log.total }} events</span>
      </div>
      <section class="panel scroll-x"><table class="table">
        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Resource</th><th>Decision</th><th class="r">Duration</th><th class="r">Rows</th><th>Detail</th></tr></thead>
        <tbody>@for (l of log.data; track l.id) {
          <tr><td class="muted small">{{ fmtDate(l.created_at, 'time') }}</td><td>{{ l.user?.name ?? 'system' }}</td><td class="mono">{{ l.action }}</td><td class="small">{{ l.resource_type }} {{ l.resource_id?.slice?.(0, 18) }}</td>
            <td><span class="badge" [class.critical]="l.decision === 'deny'" [class.good]="l.decision === 'allow' && l.result === 'success'">{{ l.decision }}{{ l.result === 'failure' ? ' · failed' : '' }}</span></td>
            <td class="r">{{ l.duration_ms ?? '' }}</td><td class="r">{{ l.row_count ?? '' }}</td>
            <td class="small muted">{{ l.query_hash ? '#' + l.query_hash.slice(0, 10) : '' }} {{ l.meta?.reason ?? '' }} {{ l.ip_address }}</td></tr>
        }</tbody></table></section>
    }

    @if (tab() === 'quality' && quality(); as q) {
      <div class="quality">@for (d of q; track d.id) {
        <section class="panel qcard"><div class="row"><b>{{ d.label }}</b><span class="spacer"></span><span class="score" [class.low]="d.quality_score < 80">{{ d.quality_score }}</span></div>
          <div class="muted small">{{ compact(d.row_count ?? 0) }} rows · latest record {{ ago(d.freshness_at) }} · profiled {{ ago(d.profiled_at) }}</div>
          @if (d.sensitive_fields.length) { <div class="small"><app-icon name="lock" [size]="12"/> Sensitive: {{ d.sensitive_fields.join(', ') }}</div> }
          @for (i of d.issues; track $index) { <div class="issue small"><span class="badge" [class.critical]="i.severity === 'critical'" [class.warning]="i.severity === 'warning'">{{ i.severity }}</span> {{ i.field ? i.field + ': ' : '' }}{{ i.message }}</div> }
          @empty { <div class="small ok">✓ No issues detected (missing values, duplicates, outliers, freshness)</div> }
        </section> }</div>
    }
  </div>`,
  styles: [`.tiles{display:grid;grid-template-columns:repeat(6,1fr);padding:18px;margin-bottom:20px;gap:10px}.tiles span{display:grid;font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.06em}
    .tiles b{font-size:22px;color:var(--ink-1);letter-spacing:-.02em;text-transform:none}.cols{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}.pad{padding:8px 16px 16px}
    section.panel{margin-bottom:20px}.small{font-size:12px}.filters{margin-bottom:12px}
    .quality{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}.qcard{padding:16px;display:grid;gap:8px;margin:0}
    .score{font-size:26px;font-weight:650;color:var(--pos)}.score.low{color:var(--neg)}.issue{display:flex;gap:8px;align-items:center}.ok{color:var(--pos)}
    @media(max-width:960px){.tiles{grid-template-columns:repeat(2,1fr)}.cols{grid-template-columns:1fr}}`],
})
export class Governance implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly tabs = computed(() => [
    ...(this.auth.can('governance.view') ? [{ key: 'ai', label: 'AI usage' }, { key: 'quality', label: 'Data quality' }] : []),
    ...(this.auth.can('audit.view') ? [{ key: 'audit', label: 'Audit trail' }] : []),
  ]);
  readonly tab = signal(this.auth.can('governance.view') ? 'ai' : 'audit');
  readonly ai = signal<any | null>(null);
  readonly audit = signal<any | null>(null);
  readonly quality = signal<any[] | null>(null);
  readonly action = signal('');
  readonly decision = signal('');
  readonly error = signal<string | null>(null);
  ago = ago; compact = compact; fmtDate = fmtDate;

  readonly runsSpec = computed<ChartSpec>(() => {
    const d = this.ai()?.by_day ?? [];
    return { kind: 'bar', isTime: true, grain: 'day', format: 'number', categories: d.map((x: any) => x.day), series: [{ key: 'runs', name: 'Runs', format: 'number', data: d.map((x: any) => +x.runs) }] };
  });
  readonly intentSpec = computed<ChartSpec>(() => {
    const d = this.ai()?.by_intent ?? [];
    return { kind: 'hbar', format: 'number', categories: d.map((x: any) => x.intent ?? 'unknown'), series: [{ key: 'runs', name: 'Runs', format: 'number', data: d.map((x: any) => +x.runs) }] };
  });

  ngOnInit() { this.load(); }
  async load() {
    this.error.set(null);
    try {
      if (this.tab() === 'ai') this.ai.set((await this.api.get('/governance/ai')).data);
      if (this.tab() === 'audit') this.audit.set(await this.api.get('/governance/audit-logs', { action: this.action(), decision: this.decision(), per_page: 100 }));
      if (this.tab() === 'quality') this.quality.set((await this.api.get('/governance/data-quality')).data);
    } catch (e) { this.error.set(errorMessage(e)); }
  }
}
