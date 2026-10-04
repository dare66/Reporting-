import { CatalogModel, Widget } from '../../../core/models';
import {
  TIME,
  changeKind,
  emptyDraft,
  fromWidget,
  functionBlocker,
  problems,
  toQuery,
  toViz,
  toWidget,
  StudioDraft,
} from './studio-model';

const catalog = {
  key: 'applications',
  metrics: [
    { key: 'total_applications', label: 'Applications', additive: true },
    { key: 'high_risk_share', label: 'High-risk share', additive: false },
  ],
  dimensions: [{ key: 'country', label: 'Country' }],
} as unknown as CatalogModel;

const draft = (change: Partial<StudioDraft> = {}): StudioDraft => ({ ...emptyDraft('applications'), ...change });

describe('Widget Studio model', () => {
  it('compiles a column chart with break-by and a quick function into a governed query', () => {
    const d = draft({
      category: TIME,
      breakBy: 'country',
      grain: 'quarter',
      values: [{ metric: 'total_applications', fn: 'running_sum' }],
      sort: { key: 'total_applications__running_sum', dir: 'desc' },
      limit: 20,
      filters: [{ dimension: 'country', op: 'not_in', value: ['China'] }],
      having: [{ metric: 'total_applications', op: 'gt', value: 10 }],
    });
    expect(toQuery(d)).toEqual({
      model: 'applications',
      metrics: ['total_applications'],
      dimensions: ['country'],
      time: { range: 'last_12_months', grain: 'quarter' },
      calculations: [{ fn: 'running_sum', metric: 'total_applications' }],
      filters: [{ dimension: 'country', op: 'not_in', value: ['China'] }],
      having: [{ metric: 'total_applications', op: 'gt', value: 10 }],
      sort: [{ key: 'total_applications__running_sum', dir: 'desc' }],
      limit: 20,
    });
    // The base metric only feeds the quick function, so it is computed but not drawn.
    expect(toViz(d).hide).toEqual(['total_applications']);
  });

  it('keeps a metric drawn when it is also shown without a quick function', () => {
    const d = draft({
      category: 'country',
      values: [{ metric: 'total_applications' }, { metric: 'total_applications', fn: 'percent_of_total' }],
    });
    expect(toQuery(d).metrics).toEqual(['total_applications']);
    expect(toViz(d).hide).toBeUndefined();
  });

  it('defaults moving averages to a three-period window', () => {
    const d = draft({ category: TIME, values: [{ metric: 'total_applications', fn: 'moving_average' }] });
    expect(toQuery(d).calculations).toEqual([{ fn: 'moving_average', metric: 'total_applications', window: 3 }]);
  });

  it('explains why a quick function does not apply', () => {
    const byCountry = draft({ category: 'country', values: [{ metric: 'high_risk_share' }] });
    expect(functionBlocker(byCountry, 'total_applications', 'running_sum', catalog)).toBe('Needs a time axis');
    expect(functionBlocker(byCountry, 'high_risk_share', 'percent_of_total', catalog)).toContain('cannot be added up');
    expect(functionBlocker(byCountry, 'total_applications', 'rank', catalog)).toBeNull();
    expect(functionBlocker(draft({ category: TIME }), 'total_applications', 'rank', catalog)).toBe(
      'Needs a dimension to rank',
    );
  });

  it('lists what is missing before anything runs', () => {
    expect(problems(draft())).toEqual(['Add a field to Category', 'Add a value']);
    expect(
      problems(draft({ kind: 'scatter', category: 'country', values: [{ metric: 'total_applications' }] })),
    ).toEqual(['Add 2 values (X value · Y value)']);
    expect(
      problems(
        draft({
          category: 'country',
          breakBy: 'region',
          values: [{ metric: 'total_applications' }, { metric: 'high_risk_share' }],
        }),
      ),
    ).toEqual(['Break by splits one value into series — keep a single value']);
  });

  it('keeps fields that fit when the chart changes, and says what it dropped', () => {
    const d = draft({
      category: TIME,
      breakBy: 'country',
      values: [{ metric: 'total_applications' }, { metric: 'high_risk_share' }],
    });
    const pie = changeKind(d, 'pie');
    expect(pie.draft.category).toBeNull(); // pies need a dimension, not time
    expect(pie.draft.breakBy).toBeNull();
    expect(pie.draft.values.length).toBe(1);
    expect(pie.dropped).toEqual(['category', 'break by', '1 value(s)']);
    expect(pie.draft.subtype).toBe('classic');

    // A moving average needs time; on a treemap it falls back to the plain value, and says so.
    const tree = changeKind(
      draft({ category: TIME, values: [{ metric: 'n', fn: 'moving_average', window: 3 }] }),
      'treemap',
    );
    expect(tree.draft.values).toEqual([{ metric: 'n' }]);
    expect(tree.dropped).toEqual(['category', 'moving average on n']);

    const heat = changeKind(draft({ category: 'country' }), 'heatmap');
    expect(heat.draft.category).toBe(TIME);

    // Time × channel becomes a pivot with channel down the side and time across.
    const pivot = changeKind(draft({ category: TIME, breakBy: 'channel', values: [{ metric: 'n' }] }), 'pivot');
    expect([pivot.draft.category, pivot.draft.breakBy, pivot.dropped]).toEqual(['channel', TIME, []]);
  });

  it('round-trips a widget it built', () => {
    const d = draft({
      title: 'Pipeline',
      kind: 'bar',
      subtype: 'stacked',
      category: 'country',
      breakBy: 'region',
      values: [{ metric: 'total_applications' }],
      viz: { legend: { enabled: true, position: 'bottom' } },
    });
    const payload = toWidget(d);
    expect(payload.type).toBe('chart');
    expect(payload.viz).toEqual({
      legend: { enabled: true, position: 'bottom' },
      type: 'bar',
      subtype: 'stacked',
      orientation: 'horizontal',
    });
    const widget = {
      id: 'w',
      dashboard_id: 'd',
      section: null,
      position: { x: 0, y: 0, w: 6, h: 4 },
      priority: 1,
      ...payload,
    } as Widget;
    expect(fromWidget(widget)).toEqual(d);
  });

  it('opens older widgets with their legacy chart types', () => {
    const base = {
      id: 'w',
      dashboard_id: 'd',
      section: null,
      position: { x: 0, y: 0, w: 6, h: 4 },
      priority: 1,
      title: 'x',
    };
    const donut = fromWidget({
      ...base,
      type: 'chart',
      query: { model: 'm', metrics: ['a'], dimensions: ['b'] },
      viz: { type: 'donut' },
    });
    expect([donut.kind, donut.subtype]).toEqual(['pie', 'donut']);
    const heat = fromWidget({
      ...base,
      type: 'chart',
      query: { model: 'm', metrics: ['a'], dimensions: ['inst'], time: { grain: 'month', range: 'last_6_months' } },
      viz: { type: 'heatmap' },
    });
    expect([heat.category, heat.breakBy, heat.range]).toEqual([TIME, 'inst', 'last_6_months']);
    const pivot = fromWidget({
      ...base,
      type: 'pivot',
      query: { model: 'm', metrics: ['a'], dimensions: ['inst'], time: { grain: 'month' } },
      viz: {},
    });
    expect([pivot.category, pivot.breakBy]).toEqual(['inst', TIME]);
  });
});
