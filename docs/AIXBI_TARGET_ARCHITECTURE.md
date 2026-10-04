# AIXBI — target architecture and backlog

The target is evolved in place from the current codebase (see `AIXBI_CURRENT_STATE_AUDIT.md`). Three properties are kept as invariants through every phase:

1. **Governed numbers only.** Every figure shown or narrated is the result of a compiled semantic query, run under the asking user's tenant, roles, row policies and column rules, and it carries an evidence hash.
2. **AI never holds data credentials.** The AI service plans and explains; the API executes.
3. **Propose, then approve.** Anything automated that changes shared state (models, metrics, dashboards, actions) is proposed with reasons and published only by a person with the right permission, and is audited.

## Layers

```
Experience   Executive home · Dashboards + Widget Studio · Reports · Auto BI Designer · AI Analyst
             Data Trust · Semantic/Metric Studio · Alerts · Governance · Administration
Platform     Identity & tenancy → Connectors (SDK) → Ingestion → Profiling & trust → Semantic layer
             & metric store → Query engine (cache, pre-aggregation) → Analytics/ML → AI gateway &
             agents → Action/workflow engine → Report engine → Lineage, audit, observability
Delivery     REST API (OpenAPI) · embedded SDK · MCP · webhooks
```

## Backlog (ordered by the brief's priority rule: security, correctness, core function, then polish)

| # | Increment | Builds on | Status |
|---|---|---|---|
| 1 | Audit, SSRF guard, Excel correctness (dates, all sheets), key-duplicate profiling | — | ✅ done |
| 2 | **Auto BI Designer**: understanding, relationships, KPI discovery, trust score, dashboard + report design, publish | semantic importer, report builder | ✅ done (v1) |
| 3 | EMGS branding: logo, colour tokens, export theme | design tokens, `Theme` | ⏭ waiting on logo and colour codes |
| 4 | Metric store: proposed → approved → certified lifecycle, owners, versions, diff/rollback | metrics table, versioning pattern from reports | ✅ done |
| 5 | Data Trust Center: trust score history per load, schema-drift detection with impact (metrics, dashboards, reports affected via lineage) | `DataTrust`, `DataLineage` | ✅ done |
| 6 | Executive home v2: "what should I know now" from KPI changes, anomalies, forecast risk and alerts, with Investigate / Act | KPI, anomaly, forecast, alert services | ⏭ |
| 7 | Source deletion cleanup (drop analytical tables, retire dependent models) and multi-hop relationships | ingestion, importer | ✅ cleanup done; multi-hop ⏭ |
| 8 | Action engine v1: approved actions (email, Teams/Slack webhook, ticket via REST) from alerts and AI, with approval and audit | notifier, alerts | ⏭ |
| 9 | AI gateway: provider abstraction (Anthropic, OpenAI, Azure, Bedrock, local), routing by task and data sensitivity, cost budgets | AI service `llm.py` | ⏭ |
| 10 | AI evaluation lab: benchmark questions with expected governed results, run in CI | planner tests | ⏭ |
| 11 | Connector SDK interface (`test/discover/preview/ingest/incremental/health`) and SQL Server, Oracle, S3, ClickHouse drivers | `Connectors` | ⏭ |
| 12 | Query acceleration: pre-aggregations for KPI cards, ClickHouse executor (dialect exists), benchmarks | executor, cache | ⏭ |
| 13 | Report Studio: template upload and mapping, DOCX export | report engine | ⏭ |
| 14 | Data Flow Studio (visual ETL) | ingestion | ⏭ |
| 15 | Identity federation (OIDC/SAML, SCIM), API keys and service accounts | auth | ⏭ |
| 16 | Embedded SDK, MCP server | API | ⏭ |
| 17 | Observability: OpenTelemetry traces API↔AI, metrics endpoint, AI cost dashboard | `ai_runs` | ⏭ |
| 18 | Mobile (PWA first, then native), voice | responsive shell | ⏭ |

Each increment ships only when its UI, API, database, security, tests and end-to-end journey all work on real data.
