import { FilterOp, QueryFilter, RankingValue } from '../../../core/models';

/** Filter families offered by the editor (Sisense: List, Text, Numeric, Ranking). */
export type FilterFamily = 'members' | 'text' | 'numeric' | 'ranking';

export const TEXT_OPS: { op: FilterOp; label: string }[] = [
  { op: 'contains', label: 'contains' },
  { op: 'not_contains', label: 'does not contain' },
  { op: 'starts_with', label: 'starts with' },
  { op: 'ends_with', label: 'ends with' },
  { op: 'eq', label: 'is exactly' },
  { op: 'neq', label: 'is not' },
];

export const NUMERIC_OPS: { op: FilterOp; label: string }[] = [
  { op: 'between', label: 'between' },
  { op: 'not_between', label: 'not between' },
  { op: 'gt', label: 'greater than' },
  { op: 'gte', label: 'at least' },
  { op: 'lt', label: 'less than' },
  { op: 'lte', label: 'at most' },
  { op: 'eq', label: 'equals' },
];

const NUMERIC_TYPES = ['number', 'integer', 'decimal', 'float', 'numeric'];
export const isNumericType = (type: string) => NUMERIC_TYPES.includes(type);

export const isRanking = (v: unknown): v is RankingValue =>
  typeof v === 'object' &&
  v !== null &&
  typeof (v as RankingValue).n === 'number' &&
  typeof (v as RankingValue).metric === 'string';

/** The editor tab a stored filter belongs to. */
export function familyOf(f: QueryFilter, dimensionType: string): FilterFamily {
  if (f.op === 'top' || f.op === 'bottom') return 'ranking';
  if (f.op === 'in' || f.op === 'not_in') return 'members';
  if (isNumericType(dimensionType) && NUMERIC_OPS.some((o) => o.op === f.op)) return 'numeric';
  return 'text';
}

const list = (values: unknown[], max = 3) => {
  const shown = values.slice(0, max).map(String).join(', ');
  return values.length > max ? `${shown} +${values.length - max}` : shown;
};

/**
 * Plain-language summary of a filter, used on chips so the state reads
 * without opening it: "Country is not China", "Institution: top 10 by Applications".
 */
export function describeFilter(
  f: QueryFilter,
  dimensionLabel: string,
  metricLabel: (key: string) => string = (k) => k,
): string {
  const v = f.value;
  const values = Array.isArray(v) ? v : [];
  switch (f.op) {
    case 'in':
      return values.length ? `${dimensionLabel}: ${list(values)}` : `${dimensionLabel}: none`;
    case 'not_in':
      return `${dimensionLabel} is not ${list(values)}`;
    case 'top':
    case 'bottom':
      return isRanking(v) ? `${dimensionLabel}: ${f.op} ${v.n} by ${metricLabel(v.metric)}` : dimensionLabel;
    case 'between':
    case 'not_between':
      return `${dimensionLabel} ${f.op === 'between' ? 'between' : 'not between'} ${values[0]} and ${values[1]}`;
    case 'is_null':
      return `${dimensionLabel} is empty`;
    case 'not_null':
      return `${dimensionLabel} is not empty`;
    default: {
      const label = [...TEXT_OPS, ...NUMERIC_OPS].find((o) => o.op === f.op)?.label ?? f.op;
      return `${dimensionLabel} ${label} “${String(v ?? '')}”`;
    }
  }
}
