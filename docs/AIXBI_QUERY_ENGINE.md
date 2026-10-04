# Query engine

```
semantic query (JSON)  ──▶  SemanticQuery::fromArray      normalise and validate untrusted input
                       ──▶  QueryService                  resolve ranking filters, choose the catalog
                       ──▶  QueryCompiler + Dialect        parameterised SQL with RLS/CLS applied
                       ──▶  QueryExecutor                  read-only transaction, timeout, cache, audit
                       ──▶  QueryResult                    rows, columns, evidence (query hash), truncation, partial period
                       ──▶  QuickFunctions                 post-query calculations
```

All code is in `apps/api/app/Domain/Query/`.

## Guarantees (built)

- Only identifiers declared in the model reach SQL, always quoted; every value is a bound parameter.
- One `SELECT` in a `READ ONLY` transaction with `statement_timeout` (`QUERY_TIMEOUT_MS`, default 15 s), on a role that can only read the analytics schema.
- `LIMIT n+1` so truncation is reported honestly (`QUERY_MAX_ROWS`, default 5,000).
- Row-level security is always ANDed in; sensitive dimensions need `data.sensitive`.
- Every execution, cached or not, is audited with its query hash; the hash is the evidence attached to every number in dashboards, reports and AI answers.

## Caching (built)

Results are cached in Redis for `QUERY_CACHE_TTL` seconds (default 300). The key combines the compiled SQL, its bindings and a **security fingerprint** (tenant, user attributes, sensitive-data access), so people with different access never share an entry. A change to a metric's definition changes its SQL, so a stale cached number cannot survive a definition change.

## Dialects

PostgreSQL is used today. A ClickHouse dialect generates SQL ([ADR 0005](adr/0005-postgres-now-clickhouse-ready.md)) but no ClickHouse executor is wired yet.

## Measured performance

On the 290k-row demo with the PHP development server: grouped queries take 30–120 ms in the database, and a cached request completes end to end in about 65 ms. Details are in [ARCHITECTURE.md](ARCHITECTURE.md#performance-strategy). The k6 script in `infra/load/k6-smoke.js` measures login, home, KPI and query latency against a running stack.

## Not built yet (brief §36)

| Capability | Plan | Status |
|---|---|---|
| Pre-aggregations for KPI cards | Materialised daily aggregates per model and time dimension, refreshed after ingestion, used when a query's grain and filters allow it | ⏭ |
| Columnar execution | ClickHouse executor behind the existing dialect, benchmarked against PostgreSQL on the demo data before adoption | ⏭ |
| In-process file analytics | DuckDB for large uploads, only if benchmarks beat loading into PostgreSQL | ⏭ evaluate |
| Query cancellation and workload classes | Cancel by query hash; separate timeouts for interactive and scheduled work | ⏭ |
| Incremental refresh | Needs CDC or watermark ingestion first (see connector architecture) | ⏭ |

No technology is added without a benchmark showing it helps on realistic data.
