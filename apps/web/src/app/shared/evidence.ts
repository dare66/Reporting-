import { CdkTrapFocus } from '@angular/cdk/a11y';
import { ChangeDetectionStrategy, Component, input, output, signal } from '@angular/core';
import { fmtDate } from '../core/format';
import { Evidence, QueryFilter } from '../core/models';
import { Icon } from './icon';
import { Scrim } from './scrim';

/** "View evidence": the queries, filters, periods and calculation behind a statement. */
@Component({
  selector: 'app-evidence',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CdkTrapFocus, Scrim, Icon],
  templateUrl: './evidence.html',
  styleUrl: './evidence.scss',
})
export class EvidenceSheet {
  readonly title = input('Supporting evidence');
  readonly body = input<string | null>(null);
  readonly calculation = input<string | null>(null);
  readonly items = input<Evidence[]>([]);
  readonly dismiss = output();
  readonly open = signal<number | null>(null);
  toggle(i: number) {
    this.open.set(this.open() === i ? null : i);
  }
  when = (d: string) => fmtDate(d, 'time');
  period = (r: string | { from: string; to: string; label?: string }) =>
    typeof r === 'string' ? r.replace(/_/g, ' ') : `${r.from} → ${r.to}${r.label ? ' (' + r.label + ')' : ''}`;
  filters = (f: QueryFilter[]) =>
    f.map((x) => `${x.dimension} ${x.op} ${Array.isArray(x.value) ? x.value.join(', ') : String(x.value)}`).join('; ');
}
