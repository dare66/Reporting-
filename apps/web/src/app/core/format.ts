import type { NumberFormat } from './models';

/** Executive formatting — identical rules to the API and AI service. */
let currency = 'RM';
export const setCurrencySymbol = (code: string) =>
  (currency = code === 'MYR' ? 'RM' : code === 'USD' ? '$' : code === 'GBP' ? '£' : code === 'EUR' ? '€' : code);

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
    case 'percent':
      return (v * 100).toFixed(1) + '%';
    case 'currency':
      return `${currency} ${compact(v)}`;
    case 'duration_days':
      return v.toFixed(1) + ' days';
    default:
      return compact(v);
  }
}

const SCALE = { K: 1e3, M: 1e6, B: 1e9 } as const;

/**
 * Formats a value with a widget's number-format override (Widget Studio).
 * Without an override, or with everything on 'auto', it is exactly fmt().
 */
export function fmtWith(v: number | null | undefined, format = 'number', nf?: NumberFormat): string {
  if (!nf || (nf.style === 'auto' && nf.decimals === 'auto' && nf.abbreviate === 'auto')) return fmt(v, format);
  if (v === null || v === undefined || Number.isNaN(v)) return '—';
  const style = nf.style === 'auto' ? (format === 'percent' || format === 'currency' ? format : 'number') : nf.style;
  if (style === 'percent') return (v * 100).toFixed(nf.decimals === 'auto' ? 1 : nf.decimals) + '%';

  let body: string;
  if (nf.abbreviate === 'auto') {
    body = nf.decimals === 'auto' ? compact(v) : abbreviated(v, nf.decimals);
  } else {
    const unit = nf.abbreviate === 'none' ? '' : nf.abbreviate;
    const scaled = unit ? v / SCALE[unit] : v;
    const decimals = nf.decimals === 'auto' ? (unit ? 1 : 0) : nf.decimals;
    body = scaled.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + unit;
  }
  return style === 'currency' ? `${currency} ${body}` : body;
}

/** compact() with a fixed number of decimals. */
function abbreviated(v: number, decimals: number): string {
  const a = Math.abs(v);
  const [scaled, unit] = a >= 1e9 ? [v / 1e9, 'B'] : a >= 1e6 ? [v / 1e6, 'M'] : a >= 1e4 ? [v / 1e3, 'K'] : [v, ''];
  return scaled.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + unit;
}

export function fmtChange(
  change: number | null | undefined,
  pct: number | null | undefined,
  format = 'number',
): string {
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
    style === 'month'
      ? { month: 'short', year: '2-digit' }
      : style === 'long'
        ? { day: 'numeric', month: 'long', year: 'numeric' }
        : style === 'time'
          ? { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }
          : { day: 'numeric', month: 'short' };
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

/** A query-result cell: metrics are formatted, other values shown as text. */
export function cell(v: string | number | boolean | null | undefined, format?: string): string {
  if (typeof v === 'number') return fmt(v, format);
  return v == null ? '—' : String(v);
}

/** "12.4s" for a duration in milliseconds; "—" when unknown. */
export function fmtDuration(ms: number | null | undefined): string {
  return ms ? `${(ms / 1000).toFixed(1)}s` : '—';
}

export const arrow = (dir?: string) => (dir === 'up' ? '▲' : dir === 'down' ? '▼' : '■');
export const RANGES = [
  { key: 'last_7_days', label: '7D' },
  { key: 'last_30_days', label: '30D' },
  { key: 'last_90_days', label: '90D' },
  { key: 'this_month', label: 'MTD' },
  { key: 'this_quarter', label: 'QTD' },
  { key: 'year_to_date', label: 'YTD' },
  { key: 'last_12_months', label: '12M' },
];
