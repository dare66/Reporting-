# Operations

## Run locally (no containers)

```bash
# PostgreSQL 16 + Redis running locally
createuser -s aixbi; createdb -O aixbi aixbi; createdb -O aixbi aixbi_test   # password: aixbi
cd apps/api && composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --seed            # ~1 min: platform data + demo tenant + 290k synthetic applications
PHP_CLI_SERVER_WORKERS=8 php artisan serve --no-reload   # multiple workers: SSE holds one
cd services/ai && python -m venv .venv && . .venv/bin/activate && pip install -r requirements-dev.txt
uvicorn app.main:app --port 8100      # set ANTHROPIC_API_KEY to enable the Claude planner/narrator
cd apps/web && npm ci && npx ng serve # http://localhost:4200 (proxies /api and /ai-api)
php artisan schedule:work             # alerts, anomaly scans, insights, report schedules
```

## Containers

`docker compose up --build` then `docker compose run --rm api php artisan migrate --seed --force` → http://localhost:8080.

## Tests

| Suite | Command |
|---|---|
| API (PostgreSQL) | `cd apps/api && php artisan test` |
| AI service | `cd services/ai && python -m pytest -q` |
| Web | `cd apps/web && npx ng test --watch=false` |
| E2E smoke (running stack) | `node tests/e2e/smoke.mjs` |

## Security checklist

- Rotate `JWT_SECRET`, `APP_KEY`, `AI_SERVICE_TOKEN`; prefer RS256 in multi-service deployments.
- The analytics DB user must only hold `SELECT` on the analytics schema (`aixbi_reader`; seeder creates it with `default_transaction_read_only`).
- Data-source credentials are encrypted with `APP_KEY` (AES-256); never logged.
- TLS terminates at the ingress; HSTS is set by the API on secure requests; CSP is set by the gateway.
- The AI service has no database route (NetworkPolicy) and authenticates every call as the user.

## Backup & disaster recovery

- PostgreSQL: continuous WAL archiving + daily base backups (PITR); target RPO 5 min, RTO 1 h. The metadata DB is small; the analytical store can be rebuilt from sources by re-running ingestion.
- Redis is a cache/queue: loss is tolerated (queries fall back to live, queued exports are retried from `report_exports` status).
- Export files (`storage/app/exports`) belong on object storage with versioning.

## Load testing

`infra/load/k6-smoke.js` exercises login, home, KPI and semantic queries. Targets from the brief: dashboard initial load < 2 s cached, common query < 1 s, AI first token immediate (SSE).
