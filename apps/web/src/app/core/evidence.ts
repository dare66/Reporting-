import { Evidence, Insight } from './models';

/** What the evidence sheet shows for a statement. */
export interface EvidenceView {
  title: string;
  body: string | null;
  calculation: string | null;
  items: Evidence[];
}

/** Collects every query behind an insight, whichever evidence layout its generator used. */
export function insightEvidence(insight: Pick<Insight, 'title' | 'body' | 'evidence'>): EvidenceView {
  const ev = insight.evidence ?? {};
  const queries = ev.queries;
  const items = [
    ...(Array.isArray(queries) ? queries : [queries?.current, queries?.previous]),
    ev.applications?.current,
    ev.capacity?.current,
    ev.query,
  ].filter((q): q is Evidence => !!q);
  return {
    title: insight.title,
    body: insight.body,
    calculation: ev.calculation ?? ev.method?.replaceAll('_', ' ') ?? null,
    items,
  };
}
