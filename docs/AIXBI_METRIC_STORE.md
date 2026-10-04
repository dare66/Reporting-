# Metric store

Every governed metric has one definition, named owners, a lifecycle and a full version history. Dashboards, reports, alerts and the AI analyst all read metrics from the same semantic layer, so a definition changed here changes everywhere at once, under review.

## Lifecycle

```
proposed ──approve──▶ approved ──certify──▶ certified
   ▲                      ▲                     │ revoke (with a reason)
   └── calculation changes: approval and certification lapse
any ──deprecate (with a reason, optional replacement)──▶ deprecated ──reinstate──▶ proposed
```

| Action | Who | Rule |
|---|---|---|
| Approve | `semantic.manage` | Only from proposed |
| Certify | `metrics.certify` (Data Engineer role, administrators) | Only from approved, and **not by the person who approved the current definition** (four-eyes) |
| Revoke | `metrics.certify` | Needs a reason |
| Deprecate | `semantic.manage` or `metrics.certify` | Needs a reason; may name a replacement in the same model |
| Reinstate | `semantic.manage` | Returns the metric to proposed |

**What lapses certification:** a change to *how the metric is computed*. That is the expression, the measures it uses (aggregation, field, filters) or the table they read. It is detected by a hash of those parts.

**What does not:** renaming, rewording the definition, changing owners, the target or the synonyms. These are still versioned.

## Versions and rollback

Every saved change is a version: the full snapshot, the person who made it and a summary. The screen shows what changed between versions field by field. *Restore* puts back an earlier definition as a new version and never rewrites history.

If the old definition used a measure that has since been redefined, the restore is refused rather than silently changing that measure. A restored calculation goes back through approval.

## Where governance shows up

- **Widget Studio** lists certified metrics first and no longer offers deprecated ones. Existing widgets that use a deprecated metric keep working.
- **The AI analyst** never chooses a deprecated metric. When two metrics match a question equally well, it prefers the certified one.
- **Imports** (semantic models as code, Auto BI) match metrics by key, so lifecycle, owners and history carry over. An identical re-import changes nothing; a changed calculation creates a version and lapses approval.
- **Auto BI**: KPIs the publishing person approved arrive as *approved*. Certifying them is a separate step by a second person.

## API

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/metric-store?status=&model=&q=&kpi=` | `semantic.view` |
| GET | `/api/v1/metric-store/{model}/{metric}` | `semantic.view` |
| PATCH | `/api/v1/metric-store/{model}/{metric}` | `semantic.manage` |
| POST | `/api/v1/metric-store/{model}/{metric}/transitions` `{action, note?, replaced_by?}` | Per action, as in the lifecycle table |
| POST | `/api/v1/metric-store/{model}/{metric}/versions/{n}/restore` | `semantic.manage` |

## Upgrading an existing install

The migration makes every existing metric *approved* version 1 and adds the `metrics.certify` permission to the Data Engineer role. Nothing in use changes.
