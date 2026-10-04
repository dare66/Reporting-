import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { ago } from '../../core/format';
import { TrustRow, TrustSummary } from '../../core/models';
import { ErrorState } from '../../shared/states';
import { Sparkline } from '../../shared/sparkline';
import { TrustDrawer } from './trust-drawer';

interface TrustResponse {
  data: TrustRow[];
  summary: TrustSummary;
}

export const grade = (score: number | null): 'high' | 'moderate' | 'low' | 'unknown' =>
  score === null ? 'unknown' : score >= 90 ? 'high' : score >= 75 ? 'moderate' : 'low';

/**
 * Data Trust Center: one trust score per dataset, how it has moved across
 * loads, and every change in the shape of the data with what it affects.
 */
@Component({
  selector: 'app-trust',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ErrorState, Sparkline, TrustDrawer],
  templateUrl: './trust.html',
  styleUrl: './trust.scss',
})
export class TrustCenterPage implements OnInit {
  private api = inject(Api);
  private router = inject(Router);
  /** Opened directly from a data-quality notification. */
  readonly dataset = input<string | undefined>();

  readonly rows = signal<TrustRow[]>([]);
  readonly summary = signal<TrustSummary | null>(null);
  readonly error = signal<string | null>(null);
  readonly attentionOnly = signal(false);
  readonly open = signal<string | null>(null);
  readonly ago = ago;
  readonly grade = grade;

  readonly shown = computed(() =>
    this.rows()
      .filter((r) => !this.attentionOnly() || (r.score !== null && r.score < 75) || r.open_drift.critical > 0)
      // Lowest trust first: what needs looking at leads.
      .sort((a, b) => (a.score ?? 101) - (b.score ?? 101)),
  );

  ngOnInit() {
    this.open.set(this.dataset() ?? null);
    this.load();
  }

  async load() {
    this.error.set(null);
    try {
      const res = await this.api.get<TrustResponse>('/trust');
      this.rows.set(res.data);
      this.summary.set(res.summary);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  points(r: TrustRow) {
    return r.history.map((value, i) => ({ period: String(i), value }));
  }

  delta(r: TrustRow): number | null {
    return r.score === null || r.previous_score === null ? null : Math.round((r.score - r.previous_score) * 10) / 10;
  }

  select(id: string | null) {
    this.open.set(id);
    this.router.navigate([], { queryParams: { dataset: id }, replaceUrl: true });
  }
}
