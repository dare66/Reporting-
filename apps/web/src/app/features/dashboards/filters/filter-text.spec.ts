import { describeFilter, familyOf } from './filter-text';

describe('filter descriptions', () => {
  const metric = (k: string) => (k === 'total_applications' ? 'Applications' : k);

  it('reads like a sentence for every filter family', () => {
    expect(describeFilter({ dimension: 'country', op: 'in', value: ['India', 'Japan'] }, 'Country')).toBe(
      'Country: India, Japan',
    );
    expect(describeFilter({ dimension: 'country', op: 'not_in', value: ['China'] }, 'Country')).toBe(
      'Country is not China',
    );
    expect(describeFilter({ dimension: 'c', op: 'in', value: ['a', 'b', 'c', 'd', 'e'] }, 'Country')).toBe(
      'Country: a, b, c +2',
    );
    expect(
      describeFilter(
        { dimension: 'institution', op: 'top', value: { n: 10, metric: 'total_applications' } },
        'Institution',
        metric,
      ),
    ).toBe('Institution: top 10 by Applications');
    expect(describeFilter({ dimension: 'i', op: 'starts_with', value: 'Uni' }, 'Institution')).toBe(
      'Institution starts with “Uni”',
    );
    expect(describeFilter({ dimension: 'age', op: 'between', value: [18, 25] }, 'Age')).toBe('Age between 18 and 25');
  });

  it('reopens a stored filter on the tab that built it', () => {
    expect(familyOf({ dimension: 'x', op: 'not_in', value: [] }, 'string')).toBe('members');
    expect(familyOf({ dimension: 'x', op: 'bottom', value: { n: 3, metric: 'm' } }, 'string')).toBe('ranking');
    expect(familyOf({ dimension: 'x', op: 'gt', value: 3 }, 'integer')).toBe('numeric');
    expect(familyOf({ dimension: 'x', op: 'eq', value: 'a' }, 'string')).toBe('text');
  });
});
