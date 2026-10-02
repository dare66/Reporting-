# API reference (v1)

Base path `/api/v1`. JSON everywhere; errors are `{"error": {"code", "message", ...}}` with friendly messages (`invalid_query` 422, `forbidden` 403, `not_found` 404, `query_failed` 503). Auth: `Authorization: Bearer <access JWT>`. Rate limits: auth 10/min per IP+email, API 240/min per user, AI 30/min.

## Identity
| Method | Path | Notes |
|---|---|---|
| POST | `/auth/login` | → tokens + user, or `{mfa_required, mfa_token}` |
| POST | `/auth/mfa/verify` | TOTP code |
| POST | `/auth/refresh` | rotating; reuse revokes the family |
| POST | `/auth/logout` | revokes refresh token |
| GET | `/me` · PATCH `/me/preferences` | profile, permissions, experience tier, data scope |
| POST | `/me/mfa/setup` · `/me/mfa/enable` · DELETE `/me/mfa` | |
| GET/DELETE | `/me/sessions[/{id}]` | session management |

## Semantic layer & queries
| Method | Path | Permission |
|---|---|---|
| GET | `/semantic-models`, `/semantic-models/{key}`, `/semantic-catalog` | semantic.view or query.run |
| GET | `/semantic-models/{key}/metrics/{metric}/lineage` | 〃 |
| POST | `/semantic-models/import` · PATCH `/semantic-models/{key}/metrics/{metric}` | semantic.manage |
| POST | `/query` | query.run — semantic query (see ARCHITECTURE.md); SQL returned only with query.explain |
| POST | `/query/explain` | query.explain |
| POST | `/kpis` | `{metrics:["model.metric"], range, compare}` → cards with change, target status, sparkline, evidence |

## Analysis
| Method | Path | Notes |
|---|---|---|
| POST | `/analysis/root-cause` | drivers, dimensions, onset, evidence |
| POST | `/analysis/drill` | next hierarchy level beneath a member |
| POST | `/analysis/forecast` | horizons `7d 30d 90d 6m 12m` (analytics.advanced) |
| POST | `/analysis/scenario` | what-if: demand / officers / productivity % |
| POST | `/analysis/anomalies/scan` · GET/PATCH `/anomalies` | |
| GET | `/insights` · POST `/insights/generate` | evidence-backed insights |
| GET | `/home` | ranked attention, summary, pulse KPIs, insights, freshness |
| GET | `/search?q=` | dashboards, reports, metrics (synonyms), datasets, insights, conversations; `ask_ai` hint |

## Dashboards
`GET/POST /dashboards`, `GET/PATCH/DELETE /dashboards/{id}` (includes desktop/tablet/mobile layouts), `PUT /dashboards/{id}/layout`, `POST/PATCH/DELETE /dashboards/{id}/widgets[/{w}]`, `POST /dashboards/{id}/widgets/{w}/data` (merges dashboard filters).

## Reports
`GET /report-templates`, `POST /report-templates`, `GET/POST /reports`, `POST /reports/generate` (`template`, `range`, `focus_metrics`, `exclude_sections`), `GET/PATCH/DELETE /reports/{id}`, sections `POST/PATCH/DELETE /reports/{id}/sections[/{s}]`, `PUT /reports/{id}/sections/order`, `POST /reports/{id}/refresh|publish|archive|duplicate|versions`, `POST /reports/{id}/versions/{v}/restore`, `GET /reports/{id}/compare?a=&b=`, exports `POST /reports/{id}/exports {format: pdf|pptx|xlsx|csv|html}` → `GET /report-exports/{id}[/download]`, schedules `POST/DELETE /reports/{id}/schedules`.

## Alerts & notifications
`GET/POST/PATCH/DELETE /alert-rules`, `POST /alert-rules/{id}/evaluate`, `GET /alerts`, `POST /alerts/{id}/acknowledge`, `GET /notifications`, `GET /notifications/stream` (SSE), `POST /notifications/{id}/read`, `POST /notifications/read-all`.

## Data platform
`GET /connectors`, `GET/POST/DELETE /data-sources`, `POST /data-sources/{id}/test|sync`, `GET /data-sources/{id}/runs`, `POST /data/upload` (CSV/Excel/JSON), `POST /ingest/webhook/{id}/{token}`, `GET /datasets[/{id}]`, `GET /datasets/{id}/preview` (masked), `POST /datasets/{id}/profile`, `GET /datasets/{id}/semantic-proposal`, `POST /datasets/{id}/semantic-model`.

## AI persistence (used by the AI service as the user)
`GET/POST /ai/conversations`, `GET/DELETE /ai/conversations/{id}`, `POST /ai/runs`, `POST /ai/runs/{id}/feedback`.

## Governance & admin
`GET /governance/audit-logs` (audit.view), `GET /governance/ai`, `/governance/ai/runs/{id}`, `/governance/data-quality`; `GET/POST/PATCH /admin/users`, `GET /admin/roles|permissions`, `POST /admin/roles`, `GET/PATCH /admin/organisation`, `GET/PUT /admin/feature-flags[/{key}]`, `GET /admin/health`; comments & bookmarks: `GET/POST /comments`, `POST /comments/{id}/resolve`, `GET /bookmarks`, `POST /bookmarks/toggle`.

## AI service (`/ai-api/v1`)
| Method | Path | Auth |
|---|---|---|
| POST | `/chat` | user JWT — full JSON result |
| POST | `/chat/stream` | user JWT — SSE: `step`, `plan`, `block`, `answer`, `done`, `error` |
| POST | `/analytics/forecast`, `/analytics/anomalies` | internal service token |
| GET | `/health` | public |
