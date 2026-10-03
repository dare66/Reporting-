import { ChartBlock } from '../core/ai-models';
import { QueryColumn, QueryResult, QueryRow } from '../core/models';
import { buildOption, ruleFor, toShares } from './chart-options';
import { MAX_SERIES, niceMax, specFromBlock, specFromGauge, specFromQuery } from './chart-spec';

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

  it('spreads one value across series by the break-by dimension', () => {
    const s = specFromQuery(
      result(
        [col('period', 'time', { grain: 'month' }), col('channel', 'dimension'), col('n', 'metric')],
        [
          { period: '2026-08-01', channel: 'Web', n: 1 },
          { period: '2026-08-01', channel: 'Agent', n: 2 },
          { period: '2026-09-01', channel: 'Web', n: 3 },
        ],
      ),
      { type: 'column', subtype: 'stacked' },
    );
    expect(s.kind).toBe('bar');
    expect(s.categories).toEqual(['2026-08-01', '2026-09-01']);
    expect(s.series.map((x) => [x.name, x.data])).toEqual([
      ['Web', [1, 3]],
      ['Agent', [2, null]],
    ]);
    expect(s.style?.stack).toBe('normal');
  });

  it('keeps the eight largest break-by members and reports the rest', () => {
    const rows = Array.from({ length: 10 }, (_, i) => ({ region: 'R', member: 'M' + i, n: i }));
    const s = specFromQuery(
      result([col('region', 'dimension'), col('member', 'dimension'), col('n', 'metric')], rows),
      { type: 'column' },
    );
    expect(s.series.length).toBe(MAX_SERIES);
    expect(s.omitted).toBe(2);
    expect(s.series.map((x) => x.key)).not.toContain('M0');
  });

  it('draws quick-function columns and hides their base metric', () => {
    const s = specFromQuery(
      result(
        [col('country', 'dimension'), col('n', 'metric'), col('n__percent_of_total', 'metric', { format: 'percent' })],
        [{ country: 'A', n: 3, n__percent_of_total: 0.75 }],
      ),
      { type: 'pie', subtype: 'donut', hide: ['n'] },
    );
    expect(s.kind).toBe('donut');
    expect(s.format).toBe('percent');
    expect(s.series.map((x) => x.key)).toEqual(['n__percent_of_total']);
  });

  it('draws nothing, rather than failing, when every value is hidden', () => {
    const r = result(
      [col('period', 'time'), col('channel', 'dimension'), col('n', 'metric')],
      [{ period: 'p', channel: 'c', n: 1 }],
    );
    expect(specFromQuery(r, { type: 'column', hide: ['n'] }).series).toEqual([]);
  });

  it('maps bar, scatter and treemap choices', () => {
    const r = result(
      [col('country', 'dimension'), col('x', 'metric'), col('y', 'metric')],
      [
        { country: 'A', x: 1, y: 2 },
        { country: 'B', x: 3, y: 4 },
      ],
    );
    expect(specFromQuery(r, { type: 'bar', orientation: 'horizontal' }).kind).toBe('hbar');
    expect(specFromQuery(r, { type: 'column' }).kind).toBe('bar');
    expect(specFromQuery(r, { type: 'treemap' }).kind).toBe('treemap');
    const scatter = specFromQuery(r, { type: 'scatter' });
    expect([scatter.kind, scatter.categories, scatter.series.map((x) => x.data)]).toEqual([
      'scatter',
      ['A', 'B'],
      [
        [1, 3],
        [2, 4],
      ],
    ]);
  });

  it('scales a gauge to the value, the target or 100% for rates', () => {
    const one = (format: string, v: number) => result([col('m', 'metric', { format })], [{ m: v }]);
    expect(specFromGauge(one('percent', 0.86)).gauge).toEqual({ min: 0, max: 1, target: null });
    expect(specFromGauge(one('number', 830), { gauge: { target: 900 } }).gauge).toEqual({
      min: 0,
      max: 2000,
      target: 900,
    });
    expect(niceMax(1080)).toBe(2000);
    expect(niceMax(0.3)).toBe(0.5);
  });
});

describe('chart options', () => {
  const theme = { token: (n: string) => n, palette: ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8'] };
  interface Opt {
    series?: { type: string; stack?: string; itemStyle?: { color?: unknown } }[];
  }

  it('turns 100% stacking into category shares', () => {
    const shares = toShares([
      { key: 'a', name: 'a', format: 'number', data: [1, 0] },
      { key: 'b', name: 'b', format: 'number', data: [3, null] },
    ]);
    expect(shares.map((s) => s.data)).toEqual([
      [0.25, null], // an all-zero category has no shares
      [0.75, null],
    ]);
  });

  it('applies the first matching status rule', () => {
    const rules = [
      { op: 'lt', value: 0.8, status: 'critical' },
      { op: 'lt', value: 0.9, status: 'warning' },
    ] as const;
    expect(ruleFor(0.75, [...rules])?.status).toBe('critical');
    expect(ruleFor(0.85, [...rules])?.status).toBe('warning');
    expect(ruleFor(0.95, [...rules])).toBeNull();
  });

  it('builds every chart family, stacking and honouring chosen colours', () => {
    const base = {
      categories: ['A', 'B'],
      format: 'number',
      series: [{ key: 'n', name: 'N', format: 'number', data: [1, 2] }],
    };
    for (const kind of ['line', 'area', 'bar', 'hbar', 'pie', 'donut', 'funnel', 'treemap', 'map', 'gauge'] as const) {
      expect(() => buildOption({ ...base, kind, gauge: { min: 0, max: 5, target: 3 } }, theme))
        .withContext(kind)
        .not.toThrow();
    }
    const stacked = buildOption({ ...base, kind: 'bar', style: { stack: 'normal', colors: { n: 3 } } }, theme) as Opt;
    expect(stacked.series?.[0].stack).toBe('total');
    expect(stacked.series?.[0].itemStyle?.color).toBe('c3');
  });
});
