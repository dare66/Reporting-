import { ChartBlock } from '../core/ai-models';
import { QueryColumn, QueryResult, QueryRow } from '../core/models';
import { specFromBlock, specFromQuery } from './chart-spec';

const col = (key: string, role: QueryColumn['role'], extra: Partial<QueryColumn> = {}): QueryColumn => ({
  key,
  label: key,
  role,
  type: role === 'time' ? 'date' : role === 'metric' ? 'number' : 'string',
  ...extra,
});

const result = (columns: QueryColumn[], rows: QueryRow[], partialFrom: string | null = null): QueryResult => ({
  columns,
  rows,
  meta: {
    query_hash: 'test',
    sql: 'SELECT 1',
    duration_ms: 1,
    cached: false,
    truncated: false,
    row_count: rows.length,
    executed_at: '2026-10-01T00:00:00Z',
    query: { model: 'test' },
    partial_from: partialFrom,
  },
});

describe('chart specs', () => {
  const timeResult = result(
    [col('period', 'time', { grain: 'month' }), col('revenue', 'metric', { label: 'Revenue', format: 'currency' })],
    [
      { period: '2026-08-01', revenue: 10 },
      { period: '2026-09-01', revenue: 12 },
      { period: '2026-10-01', revenue: 1 },
    ],
    '2026-10-01',
  );

  it('carries the in-progress period so it is never drawn as a collapse', () => {
    const s = specFromQuery(timeResult, { type: 'area' });
    expect(s.kind).toBe('area');
    expect(s.partialFrom).toBe('2026-10-01');
    expect(s.series[0].format).toBe('currency');
  });

  it('chooses horizontal bars for long categorical lists and excludes code columns', () => {
    const rows = Array.from({ length: 9 }, (_, i) => ({ country: 'C' + i, country_code: 'X' + i, n: i }));
    const s = specFromQuery(
      result(
        [col('country', 'dimension'), col('country_code', 'dimension'), col('n', 'metric', { format: 'number' })],
        rows,
      ),
    );
    expect(s.kind).toBe('hbar');
    expect(s.categories[0]).toBe('C0');
  });

  it('builds a heatmap when time and a dimension are combined', () => {
    const s = specFromQuery(
      result(
        [
          col('period', 'time', { grain: 'month' }),
          col('inst', 'dimension'),
          col('sla', 'metric', { format: 'percent' }),
        ],
        [
          { period: '2026-08-01', inst: 'A', sla: 0.9 },
          { period: '2026-09-01', inst: 'A', sla: 0.8 },
        ],
      ),
      { type: 'heatmap' },
    );
    expect(s.kind).toBe('heatmap');
    expect(s.heat?.cells.length).toBe(2);
  });

  it('maps AI breakdown blocks by metric key', () => {
    const block: ChartBlock = {
      type: 'chart',
      title: 'Applications by country',
      rows: [{ country: 'China', total_applications: 3447 }],
      dimension: { key: 'country', label: 'Country' },
      series: [{ key: 'total_applications', label: 'Applications', format: 'number' }],
      viz: { type: 'map' },
    };
    const s = specFromBlock(block);
    expect(s.kind).toBe('map');
    expect(s.series[0].data).toEqual([3447]);
  });
});
