import { CdkTrapFocus } from '@angular/cdk/a11y';
import { DecimalPipe } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  OnDestroy,
  OnInit,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Api, errorMessage } from '../../../core/api.service';
import { compact } from '../../../core/format';
import { DataSource, Envelope, SourceLoad, SourceTable } from '../../../core/models';
import { Icon } from '../../../shared/icon';
import { Scrim } from '../../../shared/scrim';

interface TablesResponse {
  data: SourceTable[];
  loaded: { id: string; physical_table: string }[];
  load: SourceLoad | null;
}

/** How many rows to read from each table, at most. */
const LIMITS = [10_000, 50_000, 200_000, 1_000_000];

/**
 * Loads a connected database: lists its tables (all selected), loads them in
 * the background with progress per table, then goes straight to Auto BI.
 */
@Component({
  selector: 'app-table-loader',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CdkTrapFocus, FormsModule, DecimalPipe, Icon, Scrim],
  templateUrl: './table-loader.html',
  styleUrl: './table-loader.scss',
})
export class TableLoader implements OnInit, OnDestroy {
  private api = inject(Api);
  private router = inject(Router);
  readonly source = input.required<DataSource>();
  readonly closed = output();
  readonly changed = output();

  readonly tables = signal<SourceTable[]>([]);
  readonly selected = signal<Set<string>>(new Set());
  readonly progress = signal<SourceLoad | null>(null);
  readonly error = signal<string | null>(null);
  readonly busy = signal(false);
  readonly limits = LIMITS;
  rowLimit = 200_000;
  compact = compact;
  private timer?: ReturnType<typeof setTimeout>;

  readonly running = computed(() => ['queued', 'running'].includes(this.progress()?.status ?? ''));
  readonly loaded = computed(() => (this.progress()?.tables ?? []).filter((t) => t.status === 'loaded').length);
  readonly finished = computed(() => this.progress()?.status === 'done');

  async ngOnInit() {
    try {
      const res = await this.api.get<TablesResponse>(`/data-sources/${this.source().id}/tables`);
      this.tables.set(res.data);
      this.selected.set(new Set(res.data.slice(0, 50).map((t) => t.name)));
      if (res.load && res.load.status !== 'done') this.follow(res.load);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  ngOnDestroy() {
    clearTimeout(this.timer);
  }

  toggle(name: string) {
    this.selected.update((s) => {
      const next = new Set(s);
      if (next.has(name)) next.delete(name);
      else next.add(name);
      return next;
    });
  }

  all(on: boolean) {
    this.selected.set(
      new Set(
        on
          ? this.tables()
              .slice(0, 50)
              .map((t) => t.name)
          : [],
      ),
    );
  }

  async start() {
    this.busy.set(true);
    this.error.set(null);
    try {
      const res = await this.api.post<Envelope<SourceLoad>>(`/data-sources/${this.source().id}/load`, {
        tables: [...this.selected()],
        row_limit: this.rowLimit,
      });
      this.follow(res.data);
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  /** Polls the source's load progress until it finishes. */
  private follow(load: SourceLoad) {
    this.progress.set(load);
    if (load.status === 'done') {
      this.changed.emit();
      return;
    }
    this.timer = setTimeout(async () => {
      try {
        const res = await this.api.get<TablesResponse>(`/data-sources/${this.source().id}/tables`);
        if (res.load) this.follow(res.load);
      } catch (e) {
        this.error.set(errorMessage(e));
      }
    }, 1500);
  }

  design() {
    this.router.navigate(['/data/sources', this.source().id, 'auto-bi']);
  }
}
