import { specFromBlock, specFromQuery } from './chart-spec';

describe('chart specs', () => {
  const timeResult = {
    columns: [{ key: 'period', role: 'time', grain: 'month' }, { key: 'revenue', role: 'metric', label: 'Revenue', format: 'currency' }],
    rows: [{ period: '2026-08-01', revenue: 10 }, { period: '2026-09-01', revenue: 12 }, { period: '2026-10-01', revenue: 1 }],
    meta: { partial_from: '2026-10-01' },
  };

  it('carries the in-progress period so it is never drawn as a collapse', () => {
    const s = specFromQuery(timeResult, { type: 'area' });
    expect(s.kind).toBe('area');
    expect(s.partialFrom).toBe('2026-10-01');
    expect(s.series[0].format).toBe('currency');
  });

  it('chooses horizontal bars for long categorical lists and excludes code columns', () => {
    const rows = Array.from({ length: 9 }, (_, i) => ({ country: 'C' + i, country_code: 'X' + i, n: i }));
    const s = specFromQuery({ columns: [{ key: 'country', role: 'dimension' }, { key: 'country_code', role: 'dimension' }, { key: 'n', role: 'metric', format: 'number' }], rows }, {});
    expect(s.kind).toBe('hbar');
    expect(s.categories[0]).toBe('C0');
  });

  it('builds a heatmap when time and a dimension are combined', () => {
    const s = specFromQuery({ columns: [{ key: 'period', role: 'time', grain: 'month' }, { key: 'inst', role: 'dimension' }, { key: 'sla', role: 'metric', format: 'percent' }],
      rows: [{ period: '2026-08-01', inst: 'A', sla: 0.9 }, { period: '2026-09-01', inst: 'A', sla: 0.8 }] }, { type: 'heatmap' });
    expect(s.kind).toBe('heatmap');
    expect(s.heat!.cells.length).toBe(2);
  });

  it('maps AI breakdown blocks by metric key', () => {
    const s = specFromBlock({ rows: [{ country: 'China', total_applications: 3447 }], dimension: { key: 'country' }, series: [{ key: 'total_applications', label: 'Applications', format: 'number' }], viz: { type: 'map' } });
    expect(s.kind).toBe('map');
    expect(s.series[0].data).toEqual([3447]);
  });
});
