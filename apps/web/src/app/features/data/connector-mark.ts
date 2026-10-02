import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/** Recognisable connector marks (monogram + family colour) — no third-party logo assets. */
const MARKS: Record<string, [string, string]> = {
  postgresql: ['Pg', '#336791'],
  mysql: ['My', '#00758f'],
  mariadb: ['Ma', '#c0765a'],
  sqlserver: ['SQL', '#cc2927'],
  oracle: ['Or', '#c74634'],
  mongodb: ['Mg', '#47a248'],
  clickhouse: ['CH', '#e2b007'],
  csv: ['CSV', '#1f8a70'],
  excel: ['XL', '#217346'],
  json: ['{ }', '#6b7280'],
  rest_api: ['API', '#3987e5'],
  graphql: ['GQ', '#e10098'],
  s3: ['S3', '#e25444'],
  webhook: ['WH', '#9085e9'],
  kafka: ['Kf', '#231f20'],
};

@Component({
  selector: 'app-connector-mark',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span
    class="mark"
    [style.background]="m()[1]"
    [style.width.px]="size()"
    [style.height.px]="size()"
    [style.font-size.px]="size() * 0.32"
    >{{ m()[0] }}</span
  >`,
  styles: [
    `
      .mark {
        display: grid;
        place-items: center;
        border-radius: 10px;
        color: #fff;
        font-weight: 700;
        letter-spacing: -0.02em;
        flex: none;
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.12);
      }
    `,
  ],
})
export class ConnectorMark {
  readonly key = input.required<string>();
  readonly size = input(40);
  readonly m = computed(() => MARKS[this.key()] ?? ['DB', '#52514e']);
}
