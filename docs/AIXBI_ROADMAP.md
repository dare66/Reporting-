# AIXBI roadmap

This is the single roadmap. Feature-level status, with files and tests, is in [`AIXBI_IMPLEMENTATION_STATUS.md`](../AIXBI_IMPLEMENTATION_STATUS.md); the ordered engineering backlog is in [`AIXBI_TARGET_ARCHITECTURE.md`](AIXBI_TARGET_ARCHITECTURE.md).

Legend: ✅ built and tested on real data · 🟡 partly built · ⏭ not started

## The brief's phases (§76)

| # | Phase | Status | What exists / what is next |
|---|---|---|---|
| 1 | Repository audit and hardening | ✅ | Audit, SSRF guard, Excel correctness, duplicate detection |
| 2 | Design system and UX | 🟡 | Token-based design system, dark and light themes, responsive shell. EMGS branding waits on the logo and colours |
| 3 | Connector framework | 🟡 | 8 working connectors, others honestly marked planned. Next: connector interface, streamed and incremental loads |
| 4 | Data profiling and Data Trust Center | ✅ | Trust score with 5 parts, history per load, schema drift with impact, owners notified |
| 5 | Data Flow Studio | ⏭ | Design in [AIXBI_DATA_FLOW_ARCHITECTURE.md](AIXBI_DATA_FLOW_ARCHITECTURE.md) |
| 6 | Semantic layer v2 | 🟡 | Measures with filters, governed metrics, row/column security, lineage. Missing: many-to-many, effective dates, richer expression language |
| 7 | Metric store | ✅ | Lifecycle, four-eyes certification, owners, versions, diff, rollback, usage |
| 8 | Auto BI Designer | ✅ | Upload or connect → understand → approve KPIs → dashboard and report. Next: multi-page dashboards, more chart kinds chosen automatically, a design critic |
| 9 | Auto report designer | 🟡 | Computed reports in 5 export formats with versions and schedules. Missing: DOCX, template upload intelligence |
| 10 | Query acceleration | 🟡 | Security-scoped result cache. Next: pre-aggregations, benchmarked columnar engine |
| 11 | Advanced analytics | 🟡 | Forecasting, anomalies, root cause, what-if. Missing: clustering, cohorts, retention, regression beyond what-if |
| 12 | AI agent architecture | 🟡 | Graph of specialised agents sharing governed context. Missing: action, data-engineering and executive agents; Agent Studio |
| 13 | AI evaluation lab | ⏭ | Unit-level AI tests exist; the benchmark suite does not |
| 14 | Action and workflow engine | ⏭ | Next AI-facing increment |
| 15 | Real-time analytics | 🟡 | Webhook ingestion and live notifications. Missing: live dashboards, Kafka, CDC |
| 16 | Embedded SDK | ⏭ | Design in [AIXBI_EMBEDDED_SDK.md](AIXBI_EMBEDDED_SDK.md) |
| 17 | Mobile and voice | 🟡 | Responsive, installable web app. Missing: push delivery, native app, voice |
| 18 | Enterprise security and governance | 🟡 | Strong core (see [AIXBI_SECURITY.md](AIXBI_SECURITY.md)). Missing: SSO, SCIM, API keys, workspaces, PII detection |
| 19 | Performance optimisation | 🟡 | Measured on the demo data; load tests not yet in CI |
| 20 | Production hardening | 🟡 | One-command Docker install with in-place upgrades; Kubernetes baseline untested in a cluster; no OpenTelemetry yet |

## Final acceptance tests (§81)

| # | Test | Status | Evidence or gap |
|---|---|---|---|
| 1 | Excel → profile, semantics, relationships, metrics, dashboard, report, narrative | ✅ | `auto-bi.mjs`, `AutoBiTest`. Automatic *cleaning* is limited to typing and header fixes |
| 2 | Database → schema, model, dashboard | 🟡 | PostgreSQL/MySQL connect, list tables and load. Auto BI then works on the loaded tables. No automated test against an external database yet |
| 3 | "Why did revenue fall last month?" with evidence | ✅ | `smoke.mjs`, analyst graph tests |
| 4 | Forecast with confidence | ✅ | Backtested forecasts with intervals |
| 5 | Root cause with ranked drivers | ✅ | Counterfactual attribution; impacts sum to the total change (tested) |
| 6 | "Create an incident" through an authorised action | ⏭ | Needs the action engine |
| 7 | One tenant cannot see another's data | ✅ | `SecurityTest::test_tenants_cannot_see_each_others_resources` |
| 8 | Cross-filtering | 🟡 | Dashboard filters update every widget. Clicking a chart to filter the others is not wired yet: charts already emit the clicked category, and the dashboard needs to apply it as a filter |
| 9 | PDF / PPTX / DOCX / XLSX | 🟡 | PDF, PPTX, XLSX, CSV, HTML built. DOCX missing |
| 10 | Dashboard usable on a phone | 🟡 | Responsive reflow built; no phone-size automated test yet |
| 11 | Break the source schema → drift detected | ✅ | `trust.mjs`, `DataTrustTest` |
| 12 | AI benchmark suite | ⏭ | Needs the evaluation lab |

## Next, in order

1. **EMGS branding**, as soon as the logo and colour codes arrive.
2. **Cross-filtering** by clicking charts (completes Test 8).
3. **Action engine v1** with approval and audit (Test 6).
4. **Executive home v2**: "what should I know right now".
5. **DOCX export** (completes Test 9) and a phone-size test pass (Test 10).
6. **AI evaluation lab** (Test 12), then the **AI gateway**.
7. **Connector interface** with streamed and incremental loads, and an automated test against a real database (Test 2).
