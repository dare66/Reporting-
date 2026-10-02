import { CdkTrapFocus } from '@angular/cdk/a11y';
import { CdkDrag, CdkDragDrop, CdkDragHandle, CdkDropList, moveItemInArray } from '@angular/cdk/drag-drop';
import {
  ChangeDetectionStrategy,
  Component,
  HostListener,
  OnInit,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ReportExporter } from '../../core/report-exporter.service';
import { ACCENTS } from '../../core/theme.service';
import { ago, fmt, fmtDate } from '../../core/format';
import {
  Envelope,
  ExportFormat,
  JsonObject,
  Report,
  ReportComparison,
  ReportSchedule,
  ReportSection as Section,
  ScheduleSettings,
} from '../../core/models';
import { Icon } from '../../shared/icon';
import { ErrorState, Working } from '../../shared/states';
import { ReportSection } from './report-section';
import { Scrim } from '../../shared/scrim';

/** Export formats: key, label, what the reader gets. */
const EXPORT_FORMATS: [ExportFormat, string, string][] = [
  ['pdf', 'PDF', 'Print-ready document'],
  ['pptx', 'PowerPoint', 'Editable native charts'],
  ['xlsx', 'Excel', 'One sheet per section'],
  ['csv', 'CSV', 'Every number, long format'],
  ['html', 'Interactive HTML', 'Shareable page'],
];

@Component({
  selector: 'app-report-view',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CdkTrapFocus,
    Scrim,
    ReportSection,
    Icon,
    FormsModule,
    RouterLink,
    CdkDropList,
    CdkDrag,
    CdkDragHandle,
    Working,
    ErrorState,
  ],
  templateUrl: './report-view.html',
  styleUrl: './report-view.scss',
})
export class ReportView implements OnInit {
  private api = inject(Api);
  private router = inject(Router);
  private exporter = inject(ReportExporter);
  readonly auth = inject(Auth);
  readonly id = input.required<string>();
  readonly report = signal<Report | null>(null);
  readonly error = signal<string | null>(null);
  readonly panel = signal<'export' | 'versions' | 'schedule' | 'add' | null>(null);
  readonly editing = signal<string | null>(null);
  readonly busy = signal<string | null>(null);
  readonly diff = signal<ReportComparison | null>(null);
  readonly compareA = signal<number | null>(null);
  readonly mobile = signal(innerWidth <= 720);
  readonly active = signal(0);
  readonly themes = ['executive', 'corporate', 'financial', 'operations', 'government'];
  readonly accents = ACCENTS;
  readonly exportFormats: [ExportFormat, string, string][] = EXPORT_FORMATS;
  readonly scheduleFormats: ExportFormat[] = ['pdf', 'pptx', 'xlsx'];
  readonly channels: ScheduleSettings['channels'] = ['email', 'push', 'in_app'];
  readonly sched = signal<ScheduleSettings>({
    frequency: 'monthly',
    time_of_day: '08:00',
    formats: ['pdf'],
    channels: ['email', 'in_app'],
  });
  ago = ago;
  fmtDate = fmtDate;

  readonly sections = computed(() => this.report()?.sections ?? []);
  readonly canEdit = computed(() => !!this.report()?.can_edit);

  ngOnInit() {
    this.load();
  }
  @HostListener('window:resize') onResize() {
    this.mobile.set(innerWidth <= 720);
  }

  async load() {
    try {
      this.report.set((await this.api.get<Envelope<Report>>(`/reports/${this.id()}`)).data);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  /** Runs a server action with a busy marker, then reloads the report. Resolves true on success. */
  async act(name: string, fn: () => Promise<unknown>): Promise<boolean> {
    this.busy.set(name);
    try {
      await fn();
      await this.load();
      return true;
    } catch (e) {
      this.error.set(errorMessage(e));
      return false;
    } finally {
      this.busy.set(null);
    }
  }

  drop(e: CdkDragDrop<Section[]>) {
    const s = [...this.sections()];
    moveItemInArray(s, e.previousIndex, e.currentIndex);
    this.report.update((r) => (r ? { ...r, sections: s } : r));
    // The optimistic order is kept on success; on failure the server's order is reloaded.
    this.act('order', () => this.api.put(`/reports/${this.id()}/sections/order`, { order: s.map((x) => x.id) }));
  }

  async saveSection(s: Section, title: string, text: string) {
    const body: { title: string; content?: JsonObject } = { title };
    if (s.type === 'summary') {
      body.content = {
        paragraphs: text
          .split(/\n{2,}/)
          .map((p) => p.trim())
          .filter(Boolean),
      };
    }
    if (s.type === 'text') body.content = { markdown: text };
    if (await this.act('section', () => this.api.patch(`/reports/${this.id()}/sections/${s.id}`, body))) {
      this.editing.set(null);
    }
  }
  deleteSection(s: Section) {
    this.act('section', () => this.api.delete(`/reports/${this.id()}/sections/${s.id}`));
  }
  async addSection(type: string, title: string, metric: string, dimension: string) {
    const extra: Record<string, JsonObject> = {
      breakdown: { dimension: dimension || 'country', limit: 10 },
      chart: { grain: 'month', range: 'last_12_months' },
      forecast: { horizon: 6 },
      anomalies: { metrics: [metric] },
      kpis: { metrics: [metric] },
      text: { markdown: 'Add commentary here.' },
    };
    const blueprint: JsonObject = { metric, ...extra[type] };
    const body = { type, title: title || type, blueprint };
    if (await this.act('section', () => this.api.post(`/reports/${this.id()}/sections`, body))) {
      this.panel.set(null);
    }
  }

  refresh() {
    this.act('refresh', () => this.api.post(`/reports/${this.id()}/refresh`));
  }
  publish() {
    this.act('publish', () => this.api.post(`/reports/${this.id()}/publish`));
  }
  archive() {
    this.act('archive', () => this.api.post(`/reports/${this.id()}/archive`));
  }
  saveVersion() {
    this.act('version', () => this.api.post(`/reports/${this.id()}/versions`, { note: 'Saved' }));
  }
  restore(v: number) {
    this.act('restore', () => this.api.post(`/reports/${this.id()}/versions/${v}/restore`));
  }
  async duplicate() {
    try {
      const r = (await this.api.post<Envelope<Report>>(`/reports/${this.id()}/duplicate`)).data;
      this.router.navigate(['/reports', r.id]);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
  setTheme(theme: string) {
    this.act('theme', () => this.api.patch(`/reports/${this.id()}`, { theme }));
  }
  async compare(b: number) {
    const a = this.compareA();
    if (a === null) {
      this.compareA.set(b);
      return;
    }
    const range = { a: Math.min(a, b), b: Math.max(a, b) };
    try {
      this.diff.set((await this.api.get<Envelope<ReportComparison>>(`/reports/${this.id()}/compare`, range)).data);
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.compareA.set(null);
    }
  }

  /** Exports run on a worker; the file downloads when it is ready. */
  async exportAs(format: ExportFormat) {
    this.busy.set('export-' + format);
    try {
      await this.exporter.download(this.id(), format, this.report()?.title ?? 'report');
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(null);
    }
  }

  patchSched<K extends keyof ScheduleSettings>(key: K, value: ScheduleSettings[K]) {
    this.sched.update((s) => ({ ...s, [key]: value }));
  }
  toggle<T extends string>(list: T[], v: T): T[] {
    return list.includes(v) ? list.filter((x) => x !== v) : [...list, v];
  }
  async schedule() {
    if (await this.act('schedule', () => this.api.post(`/reports/${this.id()}/schedules`, this.sched()))) {
      this.panel.set(null);
    }
  }
  unschedule(s: ReportSchedule) {
    this.act('schedule', () => this.api.delete(`/reports/${this.id()}/schedules/${s.id}`));
  }

  scrollTo(id: string) {
    document.getElementById('s-' + id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  onScroll(el: HTMLElement) {
    this.active.set(Math.round(el.scrollLeft / el.clientWidth));
  }
  /** Editable text of a summary or text section. */
  text(s: Section): string {
    if (s.type === 'summary') return s.content.paragraphs.join('\n\n');
    if (s.type === 'text') return s.content.markdown;
    return '';
  }
  bytes(n: number) {
    return n > 1e6 ? (n / 1e6).toFixed(1) + ' MB' : Math.round(n / 1e3) + ' KB';
  }
  f = fmt;
}
