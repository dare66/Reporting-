import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { fmtDate, fmtWith } from '../core/format';
import { QueryResult, VizOptions } from '../core/models';
import { pivot } from './pivot';

/** Pivot table: rows down, column members across (grouped per value), optional grand total. */
@Component({
  selector: 'app-pivot-table',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @let p = table();
    <table class="table pivot">
      <thead>
        @if (grouped() && perMember() > 1) {
          <!-- Several values per column member: members on top, values beneath. -->
          <tr>
            <th scope="col" rowspan="2">{{ p.rowHeader }}</th>
            @for (m of members(); track m) {
              <th scope="colgroup" class="r" [attr.colspan]="perMember()">{{ memberLabel(m) }}</th>
            }
          </tr>
          <tr>
            @for (c of p.columns; track $index) {
              <th scope="col" class="r">{{ c.metric.label }}</th>
            }
          </tr>
        } @else {
          <tr>
            <th scope="col">{{ p.rowHeader }}</th>
            @for (c of p.columns; track $index) {
              <th scope="col" class="r">{{ grouped() ? memberLabel(c.member) : c.metric.label }}</th>
            }
          </tr>
        }
      </thead>
      <tbody>
        @for (r of p.rows; track r.label) {
          <tr>
            <th scope="row">{{ r.label }}</th>
            @for (v of r.cells; track $index) {
              <td class="r num">{{ cell(v, $index) }}</td>
            }
          </tr>
        }
      </tbody>
      @if (p.totals; as totals) {
        <tfoot>
          <tr>
            <th scope="row">Total</th>
            @for (v of totals; track $index) {
              <td class="r num">{{ cell(v, $index) }}</td>
            }
          </tr>
        </tfoot>
      }
    </table>
  `,
  styles: `
    .pivot th[scope='row'] {
      font-weight: 550;
      color: var(--ink-1);
      text-align: left;
    }
    tfoot th,
    tfoot td {
      border-top: 2px solid var(--line-2);
      font-weight: 650;
    }
  `,
})
export class PivotTable {
  readonly result = input.required<QueryResult>();
  readonly viz = input<VizOptions>({});

  readonly table = computed(() => pivot(this.result(), this.viz()));
  readonly members = computed(() => [...new Set(this.table().columns.map((c) => c.member))]);
  readonly grouped = computed(() => this.members()[0] !== null);
  readonly perMember = computed(() => this.table().columns.length / Math.max(1, this.members().length));

  memberLabel(m: string | null) {
    return m === null ? '' : this.table().timeColumns ? fmtDate(m, 'month') : m;
  }
  cell(v: number | null, i: number) {
    return fmtWith(v, this.table().columns[i]?.metric.format, this.viz().number);
  }
}
