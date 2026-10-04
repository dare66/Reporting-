# AIXBI — current-state audit

Audited against the repository at commit `9dcc494` (October 2026). This is a point-in-time record: findings fixed since are marked, and current status is in [`AIXBI_IMPLEMENTATION_STATUS.md`](../AIXBI_IMPLEMENTATION_STATUS.md). The repository is the source of truth; where this document and the code disagree, the code wins and this document is wrong.

Legend: ✅ works end to end and is tested · 🟡 works but partial · 🔴 missing · ⚠️ risk

## 1. Architecture as built

```
Browser (Angular 20, zoneless, signals)
   │  HTTPS, JWT (15 min) + rotating refresh token
nginx gateway  ── strict CSP, static web build, /api → Laravel, /ai → FastAPI (SSE)
   │
Laravel 13 API (PHP 8.3)                         FastAPI AI service (Python 3.11)
 ├ Identity: JWT, refresh rotation, TOTP MFA      ├ LangGraph analyst: intent → semantic → plan →
 ├ Tenancy: fail-closed global scope               │  governance → SQL (via API) → validate →
 ├ RBAC (25 permissions) + ABAC/RLS + CLS          │  analytics → anomaly → root cause → viz → narrative
 ├ Semantic layer + query compiler (Postgres,      ├ Claude planner/narrator with grounding guard,
 │  ClickHouse dialect)                            │  deterministic planner fallback (no key needed)
 ├ Query executor (read-only role, timeout,        ├ Forecasting (backtested) and anomaly engine
 │  Redis cache, audit, evidence hash)             └ Calls the API for every query — never the DB
 ├ Dashboards, reports (PDF/PPTX/XLSX/CSV/HTML),
 │  schedules, alerts, insights, collaboration
 ├ Data platform: connectors, ingestion, profiling
 └ Queue worker + scheduler
   │
PostgreSQL 16 (app schema + `analytics` schema, separate read-only role)   Redis 7 (cache, queue, rate limits)
```

The AI service holds no database credentials. Every number it reports comes from a governed query executed by the API under the asking user's permissions, row-level policies and column rules. This is the most important architectural property in the codebase and must be kept.

## 2. Module inventory

| Module | Where | State | Notes |
|---|---|---|---|
| Authentication | `AuthController`, `JwtService`, `Totp` | ✅ | Login throttling, refresh rotation with reuse detection, MFA, sessions, password lifecycle, enforced security policy |
| Tenancy | `Support/Tenancy/*` | ✅ | Global scope on every tenant model; fail-closed when no tenant is set; tested |
| Authorisation | `RequirePermission`, roles/permissions tables | ✅ | 25 permissions, 9 platform roles, custom roles |
| Row/column security | `RowLevelPolicy`, `SecurityContext`, compiler | ✅ | Attribute-based RLS (e.g. country scope), sensitive-column masking |
| Semantic layer | `Domain/Semantic`, `Domain/Query` | ✅ | Dimensions, measures (with filters), expression metrics, joins, hierarchies, synonyms, lineage |
| Expression language | `Domain/Query/Expression` | 🟡 | Arithmetic over measures only; no time-intelligence functions (YoY, YTD) in the language itself — those exist as *quick functions* |
| Query engine | `QueryCompiler`, `QueryExecutor` | ✅ | Ranking/top-N, having, measure filters, quick functions (running sum, % of total, period-over-period) |
| Dashboards | `DashboardController`, web `features/dashboards` | ✅ | Grid reflow 12/8/4, drag/resize, filter bar, drill, Widget Studio (24 chart kinds) |
| Reports | `ReportBuilder`, `Export/*` | ✅ | Templates, AI generation, versions/compare/restore, five export formats, schedules |
| Alerts | `AlertEvaluator` | 🟡 | Threshold, change and anomaly rules; no forecast-probability rules |
| AI analyst | `services/ai` | ✅ | Streaming, evidence per answer, feedback, cost/latency recorded in `ai_runs` |
| Forecast / anomaly / root cause / what-if | `Domain/Analytics`, `services/ai/app/analytics` | ✅ | Backtested forecasts with intervals; ranked drivers with onset |
| Data connectors | `Domain/Data/Connectors` | 🟡 | Working: PostgreSQL, MySQL, MariaDB, CSV, Excel, JSON, REST, webhook. Catalogue rows for SQL Server, Oracle, MongoDB, ClickHouse, GraphQL, S3 and Kafka are marked *planned* and refuse to connect (honest, not faked) |
| Profiling | `DatasetRegistrar::profile` | 🟡 | Nulls, cardinality, ranges, top values, 4σ outliers, duplicate `id`. No trust score, no schema-drift history |
| Semantic model generation | `SemanticModelGenerator` | 🟡 | Heuristic dimensions/measures from a profiled dataset. No business-concept detection, no confidence, no KPI discovery beyond sums and averages |
| Governance | `GovernanceController` | ✅ | Audit trail, AI usage and grounding, data-quality list |
| Administration | `AdminController`, web `features/admin` | ✅ | People, roles, security policy, organisation, system health |
| 3D | `shared/globe.ts` | 🟡 | Geographic globe with a 2D twin; no other 3D scenes |
| Mobile | responsive PWA | 🟡 | Bottom navigation, offline briefing; no native app |
| Observability | `services/ai/app/observability.py`, structured logs | 🟡 | AI run telemetry stored; no OpenTelemetry traces or metrics endpoint |
| Deployment | `docker-compose.yml`, `infra/` | ✅ / 🟡 | One-command Docker run verified; Kubernetes manifest is a baseline, untested in a cluster |

## 3. Routes

**Web** (`apps/web/src/app/app.routes.ts`): landing, `home`, `insights`, `investigate`, `explore`, `ai`, `dashboards`, `dashboards/:id`, `reports`, `reports/:id`, `forecast`, `alerts`, `data`, `data/datasets/:id`, `semantic`, `governance`, `admin`, `notifications`, `settings`.

**API** (`apps/api/routes/api.php`, all under `/api/v1`, 133 routes): auth and self-service account; semantic models and catalogue; query, KPIs, explain; analysis (root cause, drill, forecast, scenario, anomalies); insights; dashboards and widgets; reports, sections, versions, exports, schedules; alert rules and history; notifications (with SSE stream); AI conversations and runs; connectors, data sources, datasets, upload, profile, semantic proposal; governance; collaboration; administration. Every authenticated route sits behind `auth.jwt`, the security-policy hold and rate limiting, and every group behind a named permission.

## 4. Database

Nine migrations, 50+ tables, UUID keys, `organisation_id` on every tenant table, timestamps throughout. Uploaded and synced data lands in typed tables in the `analytics` schema (`ds_<org>_<name>`), readable only through the `aixbi_reader` role. Semantic objects, dashboards and reports are relational, not JSON blobs, except where the shape is genuinely document-like (widget `query`/`viz`, report section content).

## 5. Testing

| Suite | Count | Covers |
|---|---|---|
| API (PHPUnit) | 103 tests, 474 assertions | Compiler, expressions, time ranges, auth/MFA, tenancy isolation, RBAC, RLS/CLS, analytics correctness, reporting, ingestion, Widget Studio, administration |
| AI service (pytest) | 25 tests | Analytics, graph, LLM guards, planner |
| Web (Karma) | 31 tests | Formatting, chart specs, pivot |
| End-to-end (Playwright) | 4 journeys | Smoke, every route, Widget Studio, administration |
| Static analysis | Pint, Larastan level 6, ruff, strict mypy, ESLint, Prettier | Enforced in CI |

## 6. Findings

### Bugs (data correctness) — all three fixed in the Auto BI change, see `AIXBI_IMPLEMENTATION_STATUS.md`

1. **Excel dates load as numbers.** `Connectors::excel` reads with `setReadDataOnly(true)`, which drops number formats, so a date cell arrives as an Excel serial (e.g. `45567`) and is typed as an integer. Any workbook with dates loses its time axis.
2. **Only the active Excel sheet is read.** Multi-sheet workbooks silently lose every other sheet.
3. **Duplicate detection only checks a column literally named `id`.** Identifier columns such as `application_id` are never checked.

### Security risks

1. ⚠️ (fixed: `OutboundGuard`) **Server-side request forgery through connectors.** A user with `data.manage` can point a REST or database source at any host, including loopback and cloud metadata addresses (`169.254.169.254`). Private network ranges must stay reachable for on-premise databases, but loopback and link-local addresses should be refused.
2. Credentials are encrypted at rest (`encrypted:array`) and hidden from serialisation — good.
3. Uploaded workbooks are read without recalculating formulas — good; external references cannot execute.

### Gaps against the target product

- No **Auto BI**: a user can upload a file and get a proposed semantic model, but nothing proposes KPIs with confidence, designs a dashboard or writes a report from it.
- No **data trust score** or **schema-drift** detection.
- No **metric certification workflow** (metrics have owners but no proposed → certified lifecycle).
- No **action / workflow engine** beyond notifications.
- No **AI gateway**: the AI service supports Anthropic plus a deterministic fallback; other providers are not abstracted.
- No **embedded SDK**, **MCP interface**, **SSO (OIDC/SAML)** or **SCIM**.
- No **data-flow (ETL) designer**.

### Duplication and debt

- Report section rendering exists twice (server exporters in PHP, on-screen in Angular). This is deliberate (exports must work without a browser) but the two must be kept in step; the `Theme` class is the single source for export styling.
- `ROADMAP.md` predates the transformation brief; `AIXBI_IMPLEMENTATION_STATUS.md` supersedes it for status tracking.

### Performance

- Query results are cached in Redis by query hash; KPI cards issue one query per card. Pre-aggregation and a columnar engine (ClickHouse dialect exists) are not yet wired.
- Profiling runs one query per column. Acceptable to a few hundred columns; should batch for wide tables.
- Ingestion holds all rows in memory (upload limit 50 MB). Streaming ingestion is needed for large files.

## 7. What should remain untouched

The tenancy scope, the security context and compiler, the evidence model (query hash per number), the AI service's no-database-access boundary, and the export pipeline. They are correct, tested and are the foundation everything in the target architecture builds on.
