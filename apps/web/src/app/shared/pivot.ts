import { QueryColumn, QueryResult, QueryRow, VizOptions } from '../core/models';

/** One value column of a pivot: a column member (if any) crossed with a metric. */
export interface PivotColumn {
  member: string | null;
  metric: QueryColumn;
}

export interface PivotRow {
  label: string;
  cells: (number | null)[];
}

export interface Pivot {
  rowHeader: string;
  /** Column members are periods (format them as dates). */
  timeColumns: boolean;
  columns: PivotColumn[];
  rows: PivotRow[];
  /** Grand-total row; only when requested, which Widget Studio offers for additive metrics alone. */
  totals: (number | null)[] | null;
}

const num = (v: QueryRow[string] | undefined): number | null =>
  typeof v === 'number' ? v : v == null ? null : Number(v);

/**
 * Pivots a query result: the first dimension (or the period) down the side,
 * the period or a second dimension across, and every value per column member.
 */
export function pivot(result: QueryResult, viz: VizOptions = {}): Pivot {
  const hidden = new Set(viz.hide ?? []);
  const time = result.columns.find((c) => c.role === 'time');
  const dims = result.columns.filter((c) => c.role === 'dimension' && !c.key.endsWith('_code'));
  const metrics = result.columns.filter((c) => c.role === 'metric' && !hidden.has(c.key));
  const rowCol = dims[0] ?? time;
  const acrossCol = rowCol === time ? undefined : (time ?? dims[1]);
  if (!rowCol) {
    return { rowHeader: '', timeColumns: false, columns: [], rows: [], totals: null };
  }

  const rowLabels = [...new Set(result.rows.map((r) => String(r[rowCol.key])))];
  const members = acrossCol ? [...new Set(result.rows.map((r) => String(r[acrossCol.key])))] : [null];
  if (acrossCol === time) members.sort();
  const columns: PivotColumn[] = members.flatMap((member) => metrics.map((metric) => ({ member, metric })));

  const at = new Map<string, QueryRow>(
    result.rows.map((r) => [`${String(r[rowCol.key])}\u0000${acrossCol ? String(r[acrossCol.key]) : ''}`, r]),
  );
  const rows = rowLabels.map((label) => ({
    label,
    cells: columns.map((c) => num(at.get(`${label}\u0000${c.member ?? ''}`)?.[c.metric.key])),
  }));
  const sum = (values: (number | null)[]) =>
    values.reduce<number | null>((total, v) => (v === null ? total : (total ?? 0) + v), null);
  const totals = viz.totals ? columns.map((_, i) => sum(rows.map((r) => r.cells[i]))) : null;

  return { rowHeader: rowCol.label, timeColumns: acrossCol === time && !!time, columns, rows, totals };
}
