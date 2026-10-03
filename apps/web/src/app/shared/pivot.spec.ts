import { QueryColumn, QueryResult, QueryRow } from '../core/models';
import { pivot } from './pivot';

const col = (key: string, role: QueryColumn['role'], extra: Partial<QueryColumn> = {}): QueryColumn => ({
  key,
  label: key,
  role,
  type: role === 'metric' ? 'number' : 'string',
  ...extra,
});
const result = (columns: QueryColumn[], rows: QueryRow[]): QueryResult => ({
  columns,
  rows,
  meta: {
    query_hash: 'h',
    sql: '',
    duration_ms: 1,
    cached: false,
    truncated: false,
    row_count: rows.length,
    executed_at: '',
    query: { model: 'm' },
    partial_from: null,
  },
});

describe('pivot', () => {
  const r = result(
    [col('period', 'time', { grain: 'month' }), col('region', 'dimension'), col('n', 'metric', { label: 'Apps' })],
    [
      { period: '2026-09-01', region: 'Asia', n: 5 },
      { period: '2026-08-01', region: 'Asia', n: 3 },
      { period: '2026-09-01', region: 'Africa', n: 2 },
    ],
  );

  it('puts the dimension down the side and sorted periods across', () => {
    const p = pivot(r);
    expect(p.rowHeader).toBe('region');
    expect(p.timeColumns).toBeTrue();
    expect(p.columns.map((c) => c.member)).toEqual(['2026-08-01', '2026-09-01']);
    expect(p.rows).toEqual([
      { label: 'Asia', cells: [3, 5] },
      { label: 'Africa', cells: [null, 2] },
    ]);
    expect(p.totals).toBeNull();
  });

  it('adds a grand total only when asked, skipping empty cells', () => {
    expect(pivot(r, { totals: true }).totals).toEqual([3, 7]);
  });

  it('keeps hidden helper columns out', () => {
    const withCalc = result(
      [col('region', 'dimension'), col('n', 'metric'), col('n__rank', 'metric')],
      [{ region: 'Asia', n: 5, n__rank: 1 }],
    );
    expect(pivot(withCalc, { hide: ['n'] }).columns.map((c) => c.metric.key)).toEqual(['n__rank']);
  });
});
