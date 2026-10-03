import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { fmtWith } from '../core/format';
import { QueryColumn, QueryResult, QueryRow, VizOptions } from '../core/models';
import { STATUS_LABEL, ruleFor } from './chart-options';

/**
 * A query result as a table, honouring the widget's number format, hidden
 * helper columns and conditional colour (applied to the first value column,
 * with the status named in text so colour is never the only signal).
 */
@Component({
  selector: 'app-result-table',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <table class="table">
      <thead>
        <tr>
          @for (c of columns(); track c.key) {
            <th [class.r]="isValue(c)" scope="col">{{ c.label }}</th>
          }
        </tr>
      </thead>
      <tbody>
        @for (r of result().rows; track $index) {
          <tr>
            @for (c of columns(); track c.key) {
              @if (isValue(c)) {
                @let status = statusOf(r, c);
                <td class="r num" [class]="status ? 'status ' + status : ''">
                  {{ value(r, c) }}
                  @if (status) {
                    <span class="visually-hidden">({{ statusLabel[status] }})</span>
                  }
                </td>
              } @else {
                <td>{{ r[c.key] ?? '—' }}</td>
              }
            }
          </tr>
        } @empty {
          <tr>
            <td [attr.colspan]="columns().length" class="muted">No rows match.</td>
          </tr>
        }
      </tbody>
    </table>
  `,
  styles: `
    td.status {
      font-weight: 600;
    }
    td.status::before {
      content: '';
      display: inline-block;
      width: 7px;
      height: 7px;
      border-radius: 50%;
      margin-right: 6px;
      vertical-align: middle;
    }
    td.good::before {
      background: var(--pos);
    }
    td.warning::before {
      background: var(--warning);
    }
    td.critical::before {
      background: var(--neg);
    }
  `,
})
export class ResultTable {
  readonly result = input.required<QueryResult>();
  readonly viz = input<VizOptions>({});
  readonly statusLabel = STATUS_LABEL;

  readonly columns = computed(() => {
    const hidden = new Set(this.viz().hide ?? []);
    return this.result().columns.filter((c) => !hidden.has(c.key) && !c.key.endsWith('_code'));
  });
  private readonly ruleColumn = computed(() => this.columns().find((c) => this.isValue(c))?.key);

  isValue(c: QueryColumn) {
    return c.role === 'metric' || c.role === 'measure';
  }
  value(r: QueryRow, c: QueryColumn) {
    const v = r[c.key];
    return typeof v === 'number' ? fmtWith(v, c.format, this.viz().number) : '—';
  }
  statusOf(r: QueryRow, c: QueryColumn) {
    if (c.key !== this.ruleColumn()) return null;
    const v = r[c.key];
    return ruleFor(typeof v === 'number' ? v : null, this.viz().conditional)?.status ?? null;
  }
}
