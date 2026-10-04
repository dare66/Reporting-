# Semantic layer

The semantic layer is the contract between data and everything that shows it. The UI, the AI analyst, reports and alerts all send *semantic queries* (metrics, dimensions, filters, time); nothing outside the query compiler writes SQL. The design rationale is in [ADR 0001](adr/0001-semantic-layer-as-contract.md); the compiler's guarantees are in [ARCHITECTURE.md](ARCHITECTURE.md#the-semantic-layer-is-the-contract).

## What a model contains (built)

| Object | Purpose | Where |
|---|---|---|
| **Semantic model** | A base (fact) dataset plus everything below; versioned on every change | `semantic_models`, `SemanticModelImporter` |
| **Relationships** | Many-to-one joins from the fact table to lookup tables; resolved breadth-first, so no fan-out | `relationships` |
| **Dimensions** | Grouping columns on the base or a joined dataset: time or string, synonyms, sensitive flag, root-cause candidate | `dimensions` |
| **Hierarchies** | Drill paths (e.g. Region → Country) used by drill-down | `hierarchies` |
| **Measures** | `count`, `count_distinct`, `sum`, `avg`, `min`, `max` over a field, each with optional filters (`approved_count = COUNT(*) WHERE status = 'approved'`) | `measures` |
| **Metrics** | Business numbers: arithmetic over measures (`approved_count / decided_count`), format, direction, target, synonyms, KPI flag | `metrics` |
| **Row-level policies** | User attribute → dimension (e.g. `country_codes` → `country_code`); no attribute means no rows | `row_level_policies` |
| **Lineage** | Field → transformation → metric edges, recorded on every import | `data_lineage` |

Models are *code*: `POST /api/v1/semantic-models/import` accepts the portable JSON definition (examples in `apps/api/database/seeders/semantic/`). Every reference is validated before anything is written.

## Governance of metrics (built)

Every metric has a lifecycle (proposed → approved → certified, or deprecated), business and data owners, a version history with diff and rollback, and usage across dashboards, reports and alerts. A change to how a metric is calculated lapses its approval. Full rules: [AIXBI_METRIC_STORE.md](AIXBI_METRIC_STORE.md).

Re-importing a model matches metrics by key, so governance survives re-imports; an identical import changes nothing.

## Expression language

**Built:** metric expressions are arithmetic over measure keys and numbers (`+ − × ÷ ( )`), parsed by a recursive-descent parser that rejects anything else. Division compiles with `NULLIF(…, 0)`. Analytical calculations that depend on the result set are *quick functions*, applied after the governed query:

| Quick function | Needs |
|---|---|
| % of total, running total, year to date | an additive metric |
| change vs previous, % change vs previous, moving average | a time axis |
| rank | — |

**Not built yet** (brief §22): median, percentile, variance and standard deviation aggregations; level-of-detail (FIXED) expressions; cohort, retention and funnel functions; CAGR. They belong in the measure aggregation set (median, percentile) and in quick functions (cohort, CAGR); each needs a dialect implementation and a test against hand-written SQL before it ships.

## Not built yet (brief §20)

- Many-to-many relationships through bridge tables (today: many-to-one only, which guarantees no double counting).
- Effective-dated definitions (valid from / to). Versions exist; effective dates do not.
- Calculated *dimensions* (bucketing, CASE expressions).
- Policy objects beyond row-level attribute policies and sensitive columns (e.g. masking rules per role).

## Where to change things

| To | Edit |
|---|---|
| Add an aggregation | `Dialect::aggregate` (both dialects) and the importer's validation |
| Add a quick function | `Domain/Query/QuickFunctions.php` |
| Change security filtering | `Domain/Query/SecurityContext.php` and `QueryCompiler` (tests in `SecurityTest`) |
