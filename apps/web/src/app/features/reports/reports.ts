import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ago } from '../../core/format';
import { Icon } from '../../shared/icon';
import { Empty, ErrorState } from '../../shared/states';

const AREAS = [
  { key: 'executive', label: 'Executive', icon: 'target', templates: ['ceo', 'board', 'monthly_management'], blurb: 'Overall performance for leadership' },
  { key: 'operations', label: 'Operations', icon: 'flow', templates: ['coo', 'operations'], blurb: 'Throughput, SLA, capacity' },
  { key: 'finance', label: 'Finance', icon: 'chart', templates: ['cfo', 'finance'], blurb: 'Revenue streams and markets' },
  { key: 'risk', label: 'Risk', icon: 'governance', templates: ['risk', 'compliance'], blurb: 'Risk indicators and refusals' },
  { key: 'custom', label: 'Custom', icon: 'ai', templates: [], blurb: 'Describe it to the AI analyst' },
];
const STAGES = ['Analysing', 'Designing', 'Writing', 'Validating', 'Finalising'];

@Component({
  selector: 'app-reports',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Empty, ErrorState],
  template: `
  <div class="page">
    <header class="page-head">
      <div><div class="eyebrow signal">Reports</div><h1>Board-ready, in minutes</h1>
        <p class="lede">Every section is computed from governed data with its evidence attached. Export to PDF, PowerPoint or Excel; version, publish and schedule.</p></div>
      @if (auth.can('reports.manage')) { <button class="btn signal" (click)="wizard.set(true)"><app-icon name="plus" [size]="16"/> Create report</button> }
    </header>

    @if (wizard()) {
      <section class="wizard panel rise">
        @if (stage() === null) {
          <div class="eyebrow ai">Step {{ area() ? 2 : 1 }} of 2</div>
          @if (!area()) {
            <h2>What do you want to understand?</h2>
            <div class="areas">@for (a of areas; track a.key) {
              <button class="area" (click)="a.key === 'custom' ? custom() : area.set(a)"><app-icon [name]="a.icon" [size]="22"/><b>{{ a.label }}</b><span>{{ a.blurb }}</span></button> }</div>
          } @else {
            <h2>{{ area()!.label }} — choose a format</h2>
            <div class="areas">@for (t of templatesFor(); track t.key) {
              <button class="area" [class.on]="template() === t.key" (click)="template.set(t.key)"><b>{{ t.name }}</b><span>{{ t.description }}</span><small>{{ t.sections.length }} sections</small></button> }</div>
            <div class="row wrap" style="margin-top:16px">
              <span class="muted">Period</span>
              <div class="seg">@for (p of periods; track p.key) { <button [class.on]="period() === p.key" (click)="period.set(p.key)">{{ p.label }}</button> }</div>
              <span class="spacer"></span><button class="btn ghost" (click)="area.set(null)">Back</button>
              <button class="btn signal" [disabled]="!template()" (click)="generate()">Generate report</button>
            </div>
          }
        } @else {
          <h2>Generating your report</h2>
          <ol class="timeline">@for (s of stages; track s; let i = $index) {
            <li [class.done]="i < stage()!" [class.active]="i === stage()"><span class="dot"></span>{{ s }}</li> }</ol>
          <p class="muted">Computing every section from live, governed data — KPIs, trends, drivers, anomalies and forecast.</p>
        }
        @if (error()) { <app-error [message]="error()!" (retry)="generate()"/> }
      </section>
    }

    <div class="filters row">
      @for (s of ['all', 'draft', 'published', 'archived']; track s) { <button class="chip" [class.on]="status() === s" (click)="status.set(s)">{{ s }}</button> }
    </div>
    <div class="list">
      @for (r of filtered(); track r.id) {
        <a class="item panel" [routerLink]="['/reports', r.id]">
          <div class="doc"><i></i><i></i><i class="s"></i><b></b></div>
          <div class="grow"><b>{{ r.title }}</b><div class="muted small">{{ r.subtitle }}</div>
            <div class="row small muted"><span class="badge" [class.good]="r.status === 'published'">{{ r.status }}</span><span>v{{ r.current_version }}</span>·<span>{{ r.sections_count }} sections</span>·<span>{{ r.owner?.name }}</span>·<span>{{ ago(r.updated_at) }}</span></div></div>
          <app-icon name="arrowRight" [size]="16"/>
        </a>
      } @empty {
        @if (loaded()) { <app-empty eyebrow="Reports" title="Your first report is one click away" body="Pick what you want to understand and AIXBI computes, writes and lays it out." [action]="auth.can('reports.manage') ? 'Create report' : null" (act)="wizard.set(true)"/> }
      }
    </div>
  </div>`,
  styles: [`.wizard{padding:24px;margin-bottom:24px;display:grid;gap:14px}
    .areas{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
    .area{display:grid;gap:6px;text-align:left;padding:16px;border-radius:var(--r-lg);border:1px solid var(--line-2);background:var(--bg-2);color:inherit;cursor:pointer;transition:all .2s var(--ease)}
    .area:hover,.area.on{border-color:var(--accent);transform:translateY(-2px)}.area app-icon{color:var(--accent)}.area span{color:var(--ink-3);font-size:12.5px}.area small{color:var(--ink-3)}
    .timeline{list-style:none;display:flex;gap:0;padding:0;margin:8px 0;flex-wrap:wrap}
    .timeline li{display:flex;align-items:center;gap:8px;padding:8px 18px 8px 0;color:var(--ink-3);font-weight:600}
    .timeline .dot{width:10px;height:10px;border-radius:50%;border:2px solid var(--ink-3)}
    .timeline li.done{color:var(--ink-1)}.timeline li.done .dot{background:var(--pos);border-color:var(--pos)}
    .timeline li.active{color:var(--accent)}.timeline li.active .dot{border-color:var(--accent);animation:pulse-ring 1.2s infinite}
    .filters{margin-bottom:12px}.filters .chip{text-transform:capitalize}
    .list{display:grid;gap:10px}.item{display:flex;align-items:center;gap:16px;padding:14px 18px;transition:border-color .2s}.item:hover{border-color:var(--line-2)}
    .doc{width:40px;height:50px;border-radius:6px;background:var(--bg-3);padding:8px 6px;display:grid;gap:4px;align-content:start;flex:none}
    .doc i{height:3px;border-radius:2px;background:var(--ink-3);opacity:.6}.doc i.s{width:60%}.doc b{height:12px;border-radius:2px;background:var(--accent);opacity:.6;margin-top:3px}
    .grow{flex:1;min-width:0;display:grid;gap:3px}.small{font-size:12px;gap:6px}`],
})
export class Reports implements OnInit {
  private api = inject(Api);
  private router = inject(Router);
  readonly auth = inject(Auth);
  readonly areas = AREAS;
  readonly stages = STAGES;
  readonly periods = [{ key: 'last_month', label: 'Last month' }, { key: 'last_quarter', label: 'Last quarter' }, { key: 'year_to_date', label: 'Year to date' }, { key: 'last_30_days', label: 'Last 30 days' }];
  readonly items = signal<any[]>([]);
  readonly loaded = signal(false);
  readonly templates = signal<any[]>([]);
  readonly status = signal('all');
  readonly wizard = signal(false);
  readonly area = signal<(typeof AREAS)[number] | null>(null);
  readonly template = signal<string | null>(null);
  readonly period = signal('last_month');
  readonly stage = signal<number | null>(null);
  readonly error = signal<string | null>(null);
  readonly filtered = computed(() => this.items().filter(r => this.status() === 'all' || r.status === this.status()));
  ago = ago;

  async ngOnInit() {
    const [r, t] = await Promise.all([this.api.get('/reports'), this.api.get('/report-templates')]);
    this.items.set(r.data);
    this.templates.set(t.data);
    this.loaded.set(true);
  }
  templatesFor() { const a = this.area(); return this.templates().filter(t => a?.templates.includes(t.key) || (a?.key === 'executive' && t.organisation_id)); }
  custom() { this.router.navigate(['/ai'], { queryParams: { q: 'Create a monthly CEO report for student applications' } }); }

  async generate() {
    this.error.set(null);
    this.stage.set(0);
    const tick = setInterval(() => this.stage.update(s => (s !== null && s < STAGES.length - 1 ? s + 1 : s)), 900);
    try {
      const r = (await this.api.post('/reports/generate', { template: this.template(), range: this.period() })).data;
      this.stage.set(STAGES.length);
      this.router.navigate(['/reports', r.id]);
    } catch (e) {
      this.error.set(errorMessage(e));
      this.stage.set(null);
    } finally { clearInterval(tick); }
  }
}
