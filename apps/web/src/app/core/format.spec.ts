import { compact, fmt, fmtChange, fmtDate, fmtWith, setDateFormat } from './format';

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

  it('applies a widget number format, and is fmt() when everything is auto', () => {
    const auto = { style: 'auto', decimals: 'auto', abbreviate: 'auto' } as const;
    expect(fmtWith(8_420_000, 'currency', auto)).toBe(fmt(8_420_000, 'currency'));
    expect(fmtWith(8_420_000, 'number', { ...auto, abbreviate: 'M', decimals: 1 })).toBe('8.4M');
    expect(fmtWith(1234.5, 'number', { ...auto, abbreviate: 'none', decimals: 2 })).toBe('1,234.50');
    expect(fmtWith(0.85891, 'percent', { ...auto, decimals: 2 })).toBe('85.89%');
    expect(fmtWith(15_913, 'number', { ...auto, decimals: 2 })).toBe('15.91K');
    expect(fmtWith(2500, 'number', { ...auto, style: 'currency', abbreviate: 'K' })).toBe('RM 2.5K');
    expect(fmtWith(null, 'number', { ...auto, decimals: 2 })).toBe('—');
  });

  it('formats dates in the order a person chose, without shifting the day', () => {
    setDateFormat('iso');
    expect(fmtDate('2026-10-04')).toBe('2026-10-04');
    expect(fmtDate('2026-10-04', 'month')).toBe('2026-10');
    setDateFormat('month_day');
    expect(fmtDate('2026-10-04')).toBe('Oct 4');
    setDateFormat('day_month');
    expect(fmtDate('2026-10-04')).toBe('4 Oct');
  });
});
