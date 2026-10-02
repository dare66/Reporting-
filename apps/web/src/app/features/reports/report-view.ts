import { CdkDrag, CdkDragDrop, CdkDragHandle, CdkDropList, moveItemInArray } from '@angular/cdk/drag-drop';
import { ChangeDetectionStrategy, Component, HostListener, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ACCENTS } from '../../core/theme.service';
import { ago, fmt, fmtDate } from '../../core/format';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';
import { ReportSection } from './report-section';

@Component({
  selector: 'app-report-view',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReportSection, Icon, FormsModule, RouterLink, CdkDropList, CdkDrag, CdkDragHandle, Working, ErrorState],
  templateUrl: './report-view.html',
  styleUrl: './report-view.scss',
})
export class ReportView implements OnInit {
  private api = inject(Api);
  private router = inject(Router);
  readonly auth = inject(Auth);
  readonly id = input.required<string>();
  readonly report = signal<any | null>(null);
  readonly error = signal<string | null>(null);
  readonly panel = signal<'export' | 'versions' | 'schedule' | 'add' | null>(null);
  readonly editing = signal<string | null>(null);
  readonly busy = signal<string | null>(null);
  readonly diff = signal<any | null>(null);
  readonly compareA = signal<number | null>(null);
  readonly mobile = signal(innerWidth <= 720);
  readonly active = signal(0);
  readonly themes = ['executive', 'corporate', 'financial', 'operations', 'government'];
  readonly accents = ACCENTS;
  readonly sched = signal({ frequency: 'monthly', time_of_day: '08:00', formats: ['pdf'], channels: ['email', 'in_app'] });
  ago = ago; fmtDate = fmtDate;

  readonly sections = computed(() => this.report()?.sections ?? []);
  readonly canEdit = computed(() => !!this.report()?.can_edit);

  ngOnInit() { this.load(); }
  @HostListener('window:resize') r() { this.mobile.set(innerWidth <= 720); }

  async load() {
    try { this.report.set((await this.api.get(`/reports/${this.id()}`)).data); } catch (e) { this.error.set(errorMessage(e)); }
  }

  async act(name: string, fn: () => Promise<any>) {
    this.busy.set(name);
    try { await fn(); await this.load(); } catch (e) { this.error.set(errorMessage(e)); } finally { this.busy.set(null); }
  }

  drop(e: CdkDragDrop<any[]>) {
    const s = [...this.sections()];
    moveItemInArray(s, e.previousIndex, e.currentIndex);
    this.report.update(r => ({ ...r, sections: s }));
    this.api.put(`/reports/${this.id()}/sections/order`, { order: s.map(x => x.id) });
  }

  saveSection(s: any, title: string, text: string) {
    const body: any = { title };
    if (s.type === 'summary') body.content = { paragraphs: text.split(/\n{2,}/).map(p => p.trim()).filter(Boolean) };
    if (s.type === 'text') body.content = { markdown: text };
    this.act('section', () => this.api.patch(`/reports/${this.id()}/sections/${s.id}`, body)).then(() => this.editing.set(null));
  }
  deleteSection(s: any) { this.act('section', () => this.api.delete(`/reports/${this.id()}/sections/${s.id}`)); }
  addSection(type: string, title: string, metric: string, dimension: string) {
    const blueprint: any = { metric };
    if (type === 'breakdown') Object.assign(blueprint, { dimension: dimension || 'country', limit: 10 });
    if (type === 'chart') Object.assign(blueprint, { grain: 'month', range: 'last_12_months' });
    if (type === 'forecast') blueprint.horizon = 6;
    if (type === 'anomalies' || type === 'kpis') blueprint.metrics = [metric];
    if (type === 'text') blueprint.markdown = 'Add commentary here.';
    this.act('section', () => this.api.post(`/reports/${this.id()}/sections`, { type, title: title || type, blueprint })).then(() => this.panel.set(null));
  }

  refresh() { this.act('refresh', () => this.api.post(`/reports/${this.id()}/refresh`)); }
  publish() { this.act('publish', () => this.api.post(`/reports/${this.id()}/publish`)); }
  archive() { this.act('archive', () => this.api.post(`/reports/${this.id()}/archive`)); }
  saveVersion() { this.act('version', () => this.api.post(`/reports/${this.id()}/versions`, { note: 'Saved' })); }
  restore(v: number) { this.act('restore', () => this.api.post(`/reports/${this.id()}/versions/${v}/restore`)); }
  async duplicate() { const r = (await this.api.post(`/reports/${this.id()}/duplicate`)).data; this.router.navigate(['/reports', r.id]); }
  setTheme(theme: string) { this.act('theme', () => this.api.patch(`/reports/${this.id()}`, { theme })); }
  async compare(b: number) {
    const a = this.compareA();
    if (a === null) { this.compareA.set(b); return; }
    this.diff.set((await this.api.get(`/reports/${this.id()}/compare`, { a: Math.min(a, b), b: Math.max(a, b) })).data);
    this.compareA.set(null);
  }

  async exportAs(format: string) {
    this.busy.set('export-' + format);
    try {
      const ex = (await this.api.post(`/reports/${this.id()}/exports`, { format })).data;
      let s = ex;
      for (let i = 0; i < 90 && !['ready', 'failed'].includes(s.status); i++) { await new Promise(r => setTimeout(r, 1000)); s = (await this.api.get(`/report-exports/${ex.id}`)).data; }
      if (s.status !== 'ready') throw { error: { error: { message: s.error ?? 'The export failed.' } } };
      const ext = format === 'pptx' ? 'pptx' : format;
      await this.api.download(`/report-exports/${ex.id}/download`, `${this.report().title.replace(/[^\w]+/g, '-').toLowerCase()}.${ext}`);
      await this.load();
    } catch (e) { this.error.set(errorMessage(e)); } finally { this.busy.set(null); }
  }

  patchSched(key: string, value: any) { this.sched.update(s => ({ ...s, [key]: value })); }
  toggle(list: string[], v: string) { return list.includes(v) ? list.filter(x => x !== v) : [...list, v]; }
  schedule() { this.act('schedule', () => this.api.post(`/reports/${this.id()}/schedules`, this.sched())).then(() => this.panel.set(null)); }
  unschedule(s: any) { this.act('schedule', () => this.api.delete(`/reports/${this.id()}/schedules/${s.id}`)); }

  scrollTo(id: string) { document.getElementById('s-' + id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }

  onScroll(el: HTMLElement) { this.active.set(Math.round(el.scrollLeft / el.clientWidth)); }
  text(s: any) { return s.type === 'summary' ? (s.content.paragraphs ?? []).join('\n\n') : s.content.markdown ?? ''; }
  bytes(n: number) { return n > 1e6 ? (n / 1e6).toFixed(1) + ' MB' : Math.round(n / 1e3) + ' KB'; }
  f = fmt;
}
