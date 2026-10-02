/** Executive formatting — identical rules to the API and AI service. */
let currency = 'RM';
export const setCurrencySymbol = (code: string) => (currency = code === 'MYR' ? 'RM' : code === 'USD' ? '$' : code === 'GBP' ? '£' : code === 'EUR' ? '€' : code);

export function compact(v: number): string {
  const a = Math.abs(v);
  if (a >= 1e9) return (v / 1e9).toFixed(2) + 'B';
  if (a >= 1e6) return (v / 1e6).toFixed(2) + 'M';
  if (a >= 1e4) return (v / 1e3).toFixed(1) + 'K';
  if (a >= 100) return Math.round(v).toLocaleString('en-US');
  return (+v.toFixed(2)).toLocaleString('en-US');
}

export function fmt(v: number | null | undefined, format = 'number'): string {
  if (v === null || v === undefined || Number.isNaN(v)) return '—';
  switch (format) {
    case 'percent': return (v * 100).toFixed(1) + '%';
    case 'currency': return `${currency} ${compact(v)}`;
    case 'duration_days': return v.toFixed(1) + ' days';
    default: return compact(v);
  }
}

export function fmtChange(change: number | null | undefined, pct: number | null | undefined, format = 'number'): string {
  if (change === null || change === undefined) return '—';
  const sign = change >= 0 ? '+' : '−';
  if (format === 'percent') return `${sign}${Math.abs(change * 100).toFixed(1)} pts`;
  if (pct !== null && pct !== undefined) return `${sign}${Math.abs(pct * 100).toFixed(1)}%`;
  return `${sign}${compact(Math.abs(change))}`;
}

export function fmtDate(d: string | null | undefined, style: 'short' | 'month' | 'long' | 'time' = 'short'): string {
  if (!d) return '—';
  const date = new Date(d.length === 10 ? d + 'T00:00:00' : d);
  const o: Intl.DateTimeFormatOptions =
    style === 'month' ? { month: 'short', year: '2-digit' } : style === 'long' ? { day: 'numeric', month: 'long', year: 'numeric' }
      : style === 'time' ? { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' } : { day: 'numeric', month: 'short' };
  return date.toLocaleDateString('en-GB', o);
}

export function ago(d: string | null | undefined): string {
  if (!d) return 'never';
  const s = (Date.now() - new Date(d).getTime()) / 1000;
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.round(s / 60)} min ago`;
  if (s < 86400) return `${Math.round(s / 3600)} h ago`;
  return `${Math.round(s / 86400)} d ago`;
}

export const arrow = (dir?: string) => (dir === 'up' ? '▲' : dir === 'down' ? '▼' : '■');
export const RANGES = [
  { key: 'last_7_days', label: '7D' }, { key: 'last_30_days', label: '30D' }, { key: 'last_90_days', label: '90D' },
  { key: 'this_month', label: 'MTD' }, { key: 'this_quarter', label: 'QTD' }, { key: 'year_to_date', label: 'YTD' }, { key: 'last_12_months', label: '12M' },
];
