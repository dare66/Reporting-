import { ChangeDetectionStrategy, Component, HostListener, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ago } from '../../core/format';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';
import { Widget } from './widget';

interface Pos { x: number; y: number; w: number; h: number }
const ROW = 64;

@Component({
  selector: 'app-dashboard-view',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Widget, Icon, FormsModule, RouterLink, Working, ErrorState],
  templateUrl: './dashboard-view.html',
  styleUrl: './dashboard-view.scss',
})
export class DashboardView implements OnInit {
  private api = inject(Api);
  private route = inject(ActivatedRoute);
  readonly auth = inject(Auth);
  readonly id = input.required<string>();

  readonly dash = signal<any | null>(null);
  readonly error = signal<string | null>(null);
  readonly editing = signal(false);
  readonly positions = signal<Record<string, Pos>>({});
  readonly breakpoint = signal<'desktop' | 'tablet' | 'mobile'>(this.bp());
  readonly section = signal<string | null>(null);
  readonly filterCountry = signal('');
  readonly countries = signal<string[]>([]);
  readonly version = signal(0);
  readonly comments = signal<any[]>([]);
  readonly comment = signal('');
  readonly showComments = signal(false);
  readonly catalog = signal<any[]>([]);
  readonly adding = signal(false);
  private undo: Record<string, Pos>[] = [];
  private redo: Record<string, Pos>[] = [];
  private drag: { id: string; mode: 'move' | 'size'; sx: number; sy: number; start: Pos; cell: number } | null = null;
  ago = ago;

  readonly cols = computed(() => ({ desktop: 12, tablet: 8, mobile: 4 })[this.breakpoint()]);
  readonly widgets = computed(() => {
    const d = this.dash();
    if (!d) return [];
    const layout: Record<string, any> = Object.fromEntries((this.editing() || this.breakpoint() === 'desktop' ? [] : d.layouts[this.breakpoint()]).map((l: any) => [l.id, l]));
    const items = d.widgets.map((w: any) => ({ ...w, pos: layout[w.id] ?? this.positions()[w.id] ?? w.position }));
    // Smaller screens follow the reflowed order (KPIs first, then by priority), not the desktop order.
    return this.breakpoint() === 'desktop' || this.editing() ? items : items.sort((a: any, b: any) => a.pos.y - b.pos.y || a.pos.x - b.pos.x);
  });
  readonly sections = computed(() => this.dash()?.sections ?? []);
  readonly filters = computed(() => (this.filterCountry() ? [{ dimension: 'country', op: 'in', value: [this.filterCountry()] }] : []));

  async ngOnInit() {
    await this.load();
    if (this.route.snapshot.queryParamMap.get('edit') && this.dash()?.can_edit) this.startEdit();
    this.api.post('/query', { model: 'applications', metrics: ['total_applications'], dimensions: ['country'], time: { range: 'last_12_months' }, sort: [{ key: 'total_applications', dir: 'desc' }], limit: 60 })
      .then(r => this.countries.set(r.rows.map((x: any) => x.country))).catch(() => {});
  }

  async load() {
    try {
      const d = (await this.api.get(`/dashboards/${this.id()}`)).data;
      this.dash.set(d);
      this.positions.set(Object.fromEntries(d.widgets.map((w: any) => [w.id, w.position])));
      this.loadComments();
    } catch (e) { this.error.set(errorMessage(e)); }
  }

  @HostListener('window:resize') onResize() { this.breakpoint.set(this.bp()); }
  private bp() { return innerWidth <= 720 ? 'mobile' : innerWidth <= 1100 ? 'tablet' : 'desktop'; }

  style(w: any) {
    const p: Pos = w.pos;
    const cols = this.cols();
    if (this.breakpoint() === 'mobile' && !this.editing()) return { 'grid-column': `span ${Math.min(4, p.w)}`, 'min-height': `${p.h * ROW}px` };
    return { 'grid-column': `${Math.min(p.x, cols - 1) + 1} / span ${Math.min(p.w, cols)}`, 'grid-row': `${p.y + 1} / span ${p.h}` };
  }
  height(w: any) { return w.pos.h * ROW + (w.pos.h - 1) * 16; }
  inSection(w: any) { return this.breakpoint() !== 'mobile' || !this.section() || w.section === this.section(); }

  applyFilter(c: string) { this.filterCountry.set(c); this.version.update(v => v + 1); }

  // ---- Edit mode: drag, resize, snap, undo/redo ----
  startEdit() { this.breakpoint.set('desktop'); this.editing.set(true); this.undo = []; this.redo = []; }
  async save() {
    const widgets = Object.entries(this.positions()).map(([id, p]) => ({ id, ...p }));
    await this.api.put(`/dashboards/${this.id()}/layout`, { widgets });
    this.editing.set(false);
    this.breakpoint.set(this.bp());
    await this.load();
  }
  cancel() { this.editing.set(false); this.breakpoint.set(this.bp()); this.load(); }
  undoStep() { const s = this.undo.pop(); if (s) { this.redo.push(this.positions()); this.positions.set(s); } }
  redoStep() { const s = this.redo.pop(); if (s) { this.undo.push(this.positions()); this.positions.set(s); } }
  canUndo() { return this.undo.length > 0; }
  canRedo() { return this.redo.length > 0; }

  pointerDown(e: PointerEvent, w: any, mode: 'move' | 'size', grid: HTMLElement) {
    if (!this.editing()) return;
    e.preventDefault();
    (e.target as HTMLElement).setPointerCapture(e.pointerId);
    this.undo.push(this.positions());
    this.redo = [];
    this.drag = { id: w.id, mode, sx: e.clientX, sy: e.clientY, start: { ...this.positions()[w.id] }, cell: (grid.clientWidth + 16) / 12 };
  }
  @HostListener('window:pointermove', ['$event'])
  pointerMove(e: PointerEvent) {
    const d = this.drag;
    if (!d) return;
    const dx = Math.round((e.clientX - d.sx) / d.cell), dy = Math.round((e.clientY - d.sy) / (ROW + 16));
    const p = { ...d.start };
    if (d.mode === 'move') { p.x = Math.max(0, Math.min(12 - p.w, p.x + dx)); p.y = Math.max(0, p.y + dy); }
    else { p.w = Math.max(2, Math.min(12 - p.x, p.w + dx)); p.h = Math.max(2, Math.min(12, p.h + dy)); }
    this.positions.update(ps => ({ ...ps, [d.id]: p }));
  }
  @HostListener('window:pointerup') pointerUp() { this.drag = null; }
  @HostListener('document:keydown', ['$event'])
  key(e: KeyboardEvent) {
    if (!this.editing() || !(e.metaKey || e.ctrlKey)) return;
    if (e.key === 'z' && !e.shiftKey) { e.preventDefault(); this.undoStep(); }
    if ((e.key === 'z' && e.shiftKey) || e.key === 'y') { e.preventDefault(); this.redoStep(); }
  }

  async removeWidget(w: any) {
    await this.api.delete(`/dashboards/${this.id()}/widgets/${w.id}`);
    this.dash.update(d => ({ ...d, widgets: d.widgets.filter((x: any) => x.id !== w.id) }));
  }
  async duplicate(w: any) {
    const nw = (await this.api.post(`/dashboards/${this.id()}/widgets`, { type: w.type, title: w.title + ' (copy)', section: w.section, query: w.query, viz: w.viz,
      position: { ...w.pos, y: w.pos.y + w.pos.h } })).data;
    this.dash.update(d => ({ ...d, widgets: [...d.widgets, nw] }));
    this.positions.update(p => ({ ...p, [nw.id]: nw.position }));
  }
  async openAdd() { this.adding.set(true); if (!this.catalog().length) this.catalog.set((await this.api.get('/semantic-catalog')).data); }
  async addWidget(type: string, ref: string, dimension: string) {
    const [model, metric] = ref.split('.');
    const maxY = Math.max(0, ...Object.values(this.positions()).map(p => p.y + p.h));
    const label = this.catalog().find(m => m.key === model)?.metrics.find((x: any) => x.key === metric)?.label ?? metric;
    const body: any = type === 'kpi'
      ? { type, title: label, query: { model, metrics: [metric], time: { range: 'last_30_days' } }, viz: { compare: 'previous_period' }, position: { x: 0, y: maxY, w: 3, h: 2 } }
      : { type: 'chart', title: dimension ? `${label} by ${dimension.replace(/_/g, ' ')}` : `${label} trend`,
          query: dimension ? { model, metrics: [metric], dimensions: [dimension], time: { range: 'last_90_days' }, sort: [{ key: metric, dir: 'desc' }], limit: 12 } : { model, metrics: [metric], time: { grain: 'month', range: 'last_12_months' } },
          viz: { type: dimension ? 'bar' : 'area' }, position: { x: 0, y: maxY, w: 6, h: 4 } };
    const nw = (await this.api.post(`/dashboards/${this.id()}/widgets`, body)).data;
    this.dash.update(d => ({ ...d, widgets: [...d.widgets, nw] }));
    this.positions.update(p => ({ ...p, [nw.id]: nw.position }));
    this.adding.set(false);
  }
  async rename(title: string) { if (title.trim()) { await this.api.patch(`/dashboards/${this.id()}`, { title }); this.dash.update(d => ({ ...d, title })); } }
  async share() {
    await this.api.patch(`/dashboards/${this.id()}`, { visibility: this.dash().visibility === 'private' ? 'organisation' : 'private' });
    this.dash.update(d => ({ ...d, visibility: d.visibility === 'private' ? 'organisation' : 'private' }));
  }

  async loadComments() { try { this.comments.set((await this.api.get('/comments', { resource_type: 'dashboard', resource_id: this.id() })).data); } catch { /* optional */ } }
  async postComment() {
    if (!this.comment().trim()) return;
    await this.api.post('/comments', { resource_type: 'dashboard', resource_id: this.id(), body: this.comment(), link: `/dashboards/${this.id()}` });
    this.comment.set('');
    this.loadComments();
  }
  metricsOf(m: any) { return m.metrics; }
}
