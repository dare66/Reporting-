# EMGS NetOps AI — Architecture

EMGS NetOps AI is a **local-first** application. Everything runs on the user's laptop: the web dashboard, the API, the
diagnostic engine, the SQLite database, report generation and the MCP server. Diagnostics run from the laptop's current
network connection. There is no server, cloud service or central database to operate. Claude is an optional add-on.

```
 ┌──────────────── User interfaces ──────────────────────────────────────────────┐
 │  Desktop browser   Mobile browser (VPN/Tailscale)   Claude Code   Codex / CLI   │
 │   http://127.0.0.1   https://<private-ip>:8751       MCP (stdio)   MCP / CLI    │
 └────────┬──────────────────┬──────────────────────────────┬──────────────────────┘
          │ HTTPS/HTTP + WS  │                              │ API token
 ┌────────▼──────────────────▼────────────┐   ┌─────────────▼───────────────┐
 │ FastAPI (app/main.py)                   │   │ mcp-server/server.py         │
 │  auth · CSRF · RBAC · rate limit        │   │ scripts/netops_cli.py        │
 │  REST /api/*   WebSocket /ws/*          │   │ (tools/netops_tools.py)      │
 └────────┬────────────────────────────────┘   └─────────────┬───────────────┘
          └──────────────────────┬────────────────────────────┘
                     same service layer (no duplicated logic)
 ┌───────────────────────────────▼──────────────────────────────────────────────┐
 │ Services: diagnostics · discovery · inventory · assistant · reports · audit    │
 │  validation → policy (approved networks/domains/ports) → rate limit →          │
 │  execute with timeout → persist → assess → audit                               │
 ├──────────────────────────────┬──────────────────────────┬─────────────────────┤
 │ AI layer                     │ Execution layer          │ Data layer          │
 │  rule parser + assessment    │  LiveBackend (Win/Linux/ │  SQLite (WAL)       │
 │  optional Claude provider    │  macOS adapters, sockets,│  migrations, backup │
 │  (interpret + explain only)  │  dnspython, httpx, TLS,  │  retention          │
 │                              │  ARP, optional Nmap)     │                     │
 │                              │  SimulatedBackend (lab)  │                     │
 └──────────────────────────────┴──────────────────────────┴─────────────────────┘
```

## Operating modes

| Mode | Purpose | Network traffic | Policy rows used |
|---|---|---|---|
| **Simulation** | Development, testing, demos, training — at home or travelling | **None.** A built-in lab model answers | All, including rows marked *sample* |
| **Live** | Real diagnostics from the laptop on the authorised EMGS network | Yes, from the laptop | Only admin-approved rows; *sample* rows are ignored |
| **Review** | Read-only review of history, inventory and reports (offline) | None; execution is refused | n/a |

The mode is stored in `application_settings.operating_mode` and shown in a banner on every page, in every result
(`simulated: true`) and on every report.

## Key components

| Path | Responsibility |
|---|---|
| `backend/app/core` | bootstrap config (env `NETOPS_*`), execution context, event bus |
| `backend/app/database` | engine/session (WAL, foreign keys), versioned migrations, seed data |
| `backend/app/security` | input validation, range parsing, target policy, password hashing, tokens, rate limiter, output sanitising |
| `backend/app/diagnostics` | `NetworkBackend` interface, `LiveBackend` (OS adapters), `SimulatedBackend`, pure output parsers |
| `backend/app/services/diagnostics.py` | the single entry point for every diagnostic |
| `backend/app/discovery` | plan → confirm → run discovery engine (pause/cancel, concurrency, rate), inventory comparison |
| `backend/app/ai` | rule-based intent parser and assessment; optional Claude provider |
| `backend/app/reports` | report data builder, Excel and PDF renderers |
| `backend/app/api` | REST routes, WebSocket endpoints, auth/CSRF/RBAC dependencies |
| `frontend/` | React + TypeScript + Vite + Tailwind dashboard (responsive, light/dark) |
| `mcp-server/` | MCP server and shared tool functions |
| `scripts/` | launcher, start/stop, CLI, backup, environment check |

## Request lifecycle (diagnostic)

1. **Mode check**: Review mode refuses execution.
2. **Role check**: `operator` or `admin`.
3. **Rate limit**: per user (default 60/min).
4. **Input validation**: IP/hostname grammar, port range, counts and timeouts; no shell metacharacters can pass.
5. **Policy**: the target must be inside an approved network, or match an approved domain or a registered inventory hostname. Every resolved address is checked again. Ports must be approved.
6. **Execution**: `asyncio.create_subprocess_exec` with a fixed argv (never a shell), or native sockets / dnspython / httpx / ssl, under a hard timeout.
7. **Persistence**: `diagnostic_jobs` (committed *before* awaiting network I/O so SQLite is never locked) and `diagnostic_results`.
8. **Assessment**: rule-based confirmed / possible causes / recommendations; Claude may rewrite the explanation.
9. **Audit**: user, time, interface, destination, port, source network, status, reference.

## Discovery lifecycle

`plan_scan` (no packets sent) → user reviews address count, methods and ports → `start_scan(confirm=true)` → asyncio task
probes each address (ICMP, TCP connect to approved ports, then ARP table, reverse DNS, optional Nmap) under a concurrency
semaphore and a token-bucket rate limit, with pause/resume/cancel → results persisted (in a worker thread) →
`compare_scan` classifies against the inventory → progress is streamed on `/ws/scans/{ref}`.

Only one discovery runs at a time. A scan interrupted by a restart is marked *failed* at the next start.

## Why these choices

* **SQLite + WAL**: zero-administration, single file, easy backup; WAL lets the dashboard and MCP server read concurrently.
* **No Alembic**: a 3-step forward-only migration table is easier to audit for a single local file.
* **Simulation backend behind the same interface**: lets all features (and the test-suite) run without touching EMGS infrastructure.
* **AI only proposes**: Claude never executes. Its output is turned into the same structured request and goes through the same validation and policy as a manual click.
