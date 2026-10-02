import { compact, fmt, fmtChange } from './format';

describe('executive formatting', () => {
  it('compacts large numbers the same way as the API', () => {
    expect(compact(8_420_000)).toBe('8.42M');
    expect(compact(15_913)).toBe('15.9K');
    expect(compact(146)).toBe('146');
  });
  it('formats by metric format', () => {
    expect(fmt(0.8589, 'percent')).toBe('85.9%');
    expect(fmt(23_415_190, 'currency')).toBe('RM 23.42M');
    expect(fmt(11.55, 'duration_days')).toBe('11.6 days');
    expect(fmt(null)).toBe('—');
  });
  it('expresses rate changes in percentage points, volumes in percent', () => {
    expect(fmtChange(-0.0496, -0.0546, 'percent')).toBe('−5.0 pts');
    expect(fmtChange(-1683, -0.067, 'currency')).toBe('−6.7%');
    expect(fmtChange(null, null)).toBe('—');
  });
});
