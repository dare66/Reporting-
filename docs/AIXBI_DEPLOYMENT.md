# Deployment

## On one machine with Docker (built and verified)

Step-by-step instructions for people are in [RUN-LOCALLY.md](RUN-LOCALLY.md). In short:

```
start.bat            (Windows)      ./start.sh     (Mac/Linux)
```

This copies `.env.example` to `.env` if needed, then runs `docker compose up -d --build`. It waits for the one-off `setup` service and opens http://localhost:8080.

| Service | Role |
|---|---|
| `postgres` | Application and analytical database (PostgreSQL 16) |
| `redis` | Query cache, queue and rate limits |
| `setup` | Runs `php artisan aixbi:install` once per start: **applies migrations every time**, and loads the demo data only when no organisation exists |
| `api`, `worker`, `scheduler` | Laravel API (php-fpm), background jobs (exports), schedules (reports, alerts, anomaly scans) |
| `ai` | FastAPI analyst and analytics engine |
| `web` | nginx gateway: the built Angular app, `/api` to Laravel, `/ai-api` to the AI service, strict CSP |

### Upgrading an existing install

Unzip the new version over the old folder, or pull it, and run the start script again. Because `setup` applies migrations on every start, the database is upgraded in place and existing data is kept. Recent upgrades that migrate data:

| Migration | What happens to existing data |
|---|---|
| `2026_10_05_000100_create_metric_store` | Every existing metric becomes *approved*, version 1; the Data Engineer role gains `metrics.certify` |
| `2026_10_05_000200_create_data_trust_tables` | Every existing dataset gets a baseline snapshot; drift is measured from its next load |

`docker compose down -v` deletes all data, including the database volume. Use it only to start completely fresh.

## Settings

Root `.env` (created from `.env.example`):

| Variable | Purpose |
|---|---|
| `AIXBI_PORT` | Port on the host (default 8080) |
| `JWT_SECRET`, `APP_KEY`, `AI_SERVICE_TOKEN` | Secrets. Change all three for any shared installation |
| `DB_PASSWORD`, `ANALYTICS_DB_PASSWORD` | Database passwords |
| `ANTHROPIC_API_KEY` | Optional; turns on the Claude planner and narrator |
| `CONNECTORS_BLOCK_PRIVATE` | `true` refuses connectors to private network addresses (for hosted, multi-tenant use) |

## Kubernetes (baseline, not yet run in a cluster)

`infra/k8s/aixbi.yaml` defines:

- namespace, ConfigMap and Secret;
- API, worker, AI and web Deployments, with Services;
- a scheduler CronJob;
- a HorizontalPodAutoscaler for the API;
- an Ingress;
- a NetworkPolicy that gives the AI service no route to the database.

It has not been applied to a real cluster yet, so treat it as a starting point and validate it in a test cluster first.

## Environments (brief §48) — not built yet

Development, test, UAT and production are separate deployments today, each with its own database. Promoting semantic models, metrics, dashboards and reports between them is not built. The basis exists: semantic models are portable JSON ("models as code") and metrics are versioned. The planned design exports a signed bundle from one environment and imports it into another, where the importer's sync-by-key keeps governance and history.

Operations (backups, disaster recovery, load testing, security checklist): [OPERATIONS.md](OPERATIONS.md).
