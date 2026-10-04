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
| EMGS branding | ⏭ | — | — | — | Needs the official logo and colour codes |
| Everything else in the brief | ⏭ | — | — | — | See backlog |

## Verification for this change

- API: 106 tests, 545 assertions, Pint and Larastan level 6 clean.
- Web: 31 unit tests; ESLint, Prettier and the production build clean.
- Browser (Playwright, against a running stack): Auto BI, smoke and every-route journeys pass. Auto BI uses `tests/e2e/fixtures/Student Applications.xlsx`, a three-sheet workbook with 2,393 applications.
