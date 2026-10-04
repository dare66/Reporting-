# AIXBI implementation status

The live tracker for the transformation brief. ✅ works end to end on real data and is tested · 🟡 partial · ⏭ not started. The ordered backlog is in `docs/AIXBI_TARGET_ARCHITECTURE.md`.

| Feature | Status | Files | Tests | Security | Notes |
|---|---|---|---|---|---|
| Current-state audit | ✅ | `docs/AIXBI_CURRENT_STATE_AUDIT.md` | — | — | Findings fixed below |
| Connector SSRF guard | ✅ | `Domain/Data/OutboundGuard.php`, `Connectors.php` | `AutoBiTest` (2 tests) | Refuses loopback, link-local and metadata addresses (also IPv4-mapped IPv6); no redirects; `CONNECTORS_BLOCK_PRIVATE` for hosted deployments | Private networks allowed by default for on-premise databases |
| Excel: dates, every sheet | ✅ | `Connectors.php`, `DataController::upload` | `AutoBiTest`, `auto-bi.mjs` | Formulas still never recalculated | One dataset per sheet, labelled by sheet name |
| Duplicate keys and rows in profiling | ✅ | `DatasetRegistrar::profile` | `AutoBiTest` | — | Checks `id` and near-unique `…_id` columns, plus exact duplicate rows |
| Auto BI: data understanding | ✅ | `Domain/AutoBi/DataUnderstanding.php` | `AutoBiTest` | Reads profiles only | Role, concept, confidence and reasons for each column; domain detection |
| Auto BI: relationship discovery | ✅ | `RelationshipDiscovery.php` | `AutoBiTest` (100% coverage asserted) | Read-only analytical role | Value-verified, one hop |
| Auto BI: KPI discovery | ✅ | `KpiDiscovery.php` | `AutoBiTest` | — | Volume, money, durations, rates, status outcome rates, yes/no shares |
| Data trust score | 🟡 | `DataTrust.php` | `AutoBiTest` | — | Score per load; history and schema drift are next |
| Auto BI: dashboard and report design | ✅ | `AutoBiDesigner.php` | `AutoBiTest` checks every widget returns data; `auto-bi.mjs` checks rendering | Publishing needs four manage permissions; audited | Executive and operations audiences |
| Auto BI screen | ✅ | `apps/web/src/app/features/data/auto-bi/` | `tests/e2e/auto-bi.mjs` | Publish is hidden or disabled without permission | Understand → approve KPIs → design → publish |
| Metric store: lifecycle and four-eyes certification | ✅ | `Domain/Metrics/MetricStore.php`, migration `2026_10_05_000100` | `MetricStoreTest` (6), `tests/e2e/metrics.mjs` | Approve, certify, revoke, deprecate each permission-checked; certifier ≠ approver; audited | `docs/AIXBI_METRIC_STORE.md` |
| Metric store: versions, diff, rollback | ✅ | `MetricDefinition.php`, `metric_versions` table | `MetricStoreTest` | Restore refuses to overwrite a redefined measure | A calculation change lapses approval |
| Metric store: owners and usage | ✅ | `MetricUsage.php`, `MetricStoreController.php` | `MetricStoreTest` | Usage counts private items but never names them | Business and data owner per metric |
| Governed metrics in Studio and AI | ✅ | `widget-studio.html`, `services/ai/app/agents/catalog.py` | `test_governed_metrics.py` | Deprecated metrics are never chosen for new work | Certified metrics listed first and preferred |
| Imports keep governance | ✅ | `SemanticModelImporter::syncMetrics` | `MetricStoreTest` | — | Sync by key replaces delete-and-recreate |
| EMGS branding | ⏭ | — | — | — | Needs the official logo and colour codes |
| Everything else in the brief | ⏭ | — | — | — | See backlog |

## Verification (latest change: metric store)

- API: 112 tests, 604 assertions; Pint and Larastan level 6 clean. The migration was also run on a database that already had data.
- AI service: 27 tests; ruff and strict mypy clean.
- Web: 31 unit tests; ESLint, Prettier and the production build clean.
- Browser (Playwright, against a running stack): smoke, every route, Widget Studio, administration, Auto BI and metric store journeys all pass.
