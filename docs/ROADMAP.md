# Delivery status & roadmap

Legend: ✅ implemented and tested · 🟡 implemented, partial · ⏭ next

| Phase | Scope | Status |
|---|---|---|
| 1 Foundation | Monorepo, JWT + rotating refresh, TOTP MFA, multi-tenancy (fail-closed), RBAC (25 permissions, 9 roles), ABAC/RLS, CLS, audit, rate limits, migrations (50+ tables, UUIDs), design system | ✅ |
| 2 Data platform | Connector framework & catalogue (15 connectors; PostgreSQL/MySQL/MariaDB/CSV/Excel/JSON/REST/Webhook working; SQL Server/Oracle/MongoDB/ClickHouse/GraphQL/S3/Kafka marked *roadmap* in UI), ingestion runs & health, profiling (nulls, cardinality, ranges, outliers, duplicates, freshness), semantic layer, AI-proposed semantic models | ✅ / 🟡 CDC & incremental watermarking |
| 3 BI engine | Semantic compiler (joins, RLS, CLS, measures w/ filters, expression metrics), executor (read-only, timeout, cache, audit), dashboards (12/8/4 reflow, drag/resize/snap/undo), filters, drill-down via hierarchies, drill-through | ✅ |
| 4 AI | LangGraph analyst, Claude + deterministic planners, grounded narrative, insights, AI report builder & conversational edits, alerts/dashboards by conversation, SSE streaming | ✅ |
| 5 Advanced analytics | Anomaly detection, forecasting (7d–12m) with backtests, root cause with onset, what-if fitted on history | ✅ |
| 6 3D | Three.js globe with drill and 2D twin | 🟡 3D business landscape / operations centre ⏭ |
| 7 Reporting | Templates (9), builder, versions/compare/restore, PDF/PPTX/XLSX/CSV/HTML, scheduling (email/push/in-app) | ✅ |
| 8 Mobile | Responsive PWA (bottom nav, reflow, swipe reader, offline briefing, push-intelligence messages) | 🟡 Flutter app ⏭ (consumes the same API) |
| 9 Enterprise | Governance (AI usage/cost/grounding, audit trail, data quality), lineage, collaboration (comments, @mentions incl. departments, bookmarks), admin (users, roles matrix, org branding, flags, live health) | ✅ / 🟡 approval workflow ⏭ |
| 10 Production | Dockerfiles, compose, nginx gateway, Kubernetes baseline (HPA, NetworkPolicy), CI | 🟡 observability stack, load tests in CI, DR runbooks ⏭ |

## Next, in order

1. **Flutter mobile app** (Phase 8): Home briefing, AI chat (SSE), dashboards via the mobile layout endpoint, report reader, FCM push with deep links into `/investigate`, biometric unlock, secure token storage, offline cache.
2. **Scale-out analytics**: ClickHouse executor (dialect already done), materialised pre-aggregations for KPI cards, DuckDB for in-process file analytics, Arrow transport.
3. **OIDC/SAML** identity federation and SCIM provisioning.
4. **3D business landscape & operations centre** scenes (with 2D twins).
5. **CDC/incremental ingestion**, SQL Server/Oracle/MongoDB/S3/Kafka drivers.
6. **Approval workflow** for report publishing; annotations on charts.
7. **Observability**: OpenTelemetry traces API↔AI, Prometheus metrics, k6 load tests in CI (script in `infra/load`).
