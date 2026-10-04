# Auto BI engine

Auto BI turns a connected source into a governed semantic model, a dashboard and an executive report. It proposes; a person approves. Nothing is published without approval, and every number in the output is a governed query against the source's real data.

```
Source (one or more tables, e.g. every sheet of a workbook)
  → DataUnderstanding      what each column means: role, business concept, confidence, reasons
  → RelationshipDiscovery  how tables join, proven by the values (≥ 90% of references found)
  → KpiDiscovery           KPIs with formula, direction, confidence and reason
  → DataTrust              0–100 trust score from completeness, uniqueness, validity, freshness
  → AutoBiDesigner.plan    dashboard blueprint + report outline, each widget with its rationale
  ── person reviews, renames, approves KPIs, picks the audience ──
  → AutoBiDesigner.publish semantic model (approved KPIs become governed metrics),
                           dashboard, report (built by the existing ReportBuilder), audit record
```

Code: `apps/api/app/Domain/AutoBi/`. API: `GET /api/v1/data-sources/{id}/auto-bi` (needs `data.view`) and `POST` the same path (needs `data.manage`, `semantic.manage`, `dashboards.manage` and `reports.manage`). Screen: `/data/sources/:id/auto-bi`; uploading a file opens it directly.

## Understanding

Each column gets a role: identifier, time, measure, dimension, geography, status, flag, text or ignored. The evidence is the type, the name, the profile (cardinality, uniqueness, nulls, ranges) and the values (lifecycle words such as Approved or Rejected). Each conclusion comes with a confidence and plain-language reasons. Anything below 75% confidence, and a business domain below 60%, becomes a question shown to the person before publishing.

The rules are deterministic, so the same data always gives the same proposal and every conclusion can be explained. An LLM can later be added as a second opinion on concepts and names. It must not replace the evidence.

## KPIs

| Found | Proposed KPI | Formula |
|---|---|---|
| any table | Volume | `COUNT(rows)` |
| money column | Total | `SUM(column)` |
| duration column | Average, lower is better | `AVG(column)` |
| rate column | Average, never summed | `AVG(column)` |
| lifecycle status | Outcome rates, direction from the outcome | `COUNT(WHERE status = 'Approved') ÷ COUNT(rows)` |
| yes/no column | Share of yes | `COUNT(WHERE flag) ÷ COUNT(rows)` |

The first four with confidence of at least 75% are recommended. Approved KPIs are stored as semantic-layer metrics with `is_kpi`, a description holding the formula and the reason, and their direction (higher or lower is better).

## Design

The dashboard answers questions in reading order:

1. KPI strip: what happened.
2. Trend: how it is changing.
3. Composition: what it is made of.
4. Ranking: where.
5. Worst-first ranking of a rate or duration: what needs attention.
6. Scorecard table: the detail.

The *operations* audience adds a time × category heatmap and a longer table.

Uploaded extracts often end months ago, so queries are anchored to the period the data covers. KPI cards compare the latest 30 days in the data with the 30 days before. Forecasts are only included when the data is current, because the forecasting engine projects forward from today.

## Limits (next increments)

- **Relationships:** only direct joins from the main table (one hop).
- **Re-publishing:** replaces the semantic model with a new version and creates a new dashboard and report each time.
- **Metric certification:** approved KPIs are published as governed metrics. A separate certified status is part of the Metric Store phase.
- **Visualisation choices:** waterfall, Sankey, sunburst and bullet charts are not yet chosen automatically.
