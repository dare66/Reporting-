# REST and WebSocket API

Base URL: `http://127.0.0.1:8750` (mobile: `https://<private-ip>:8751`). The interactive OpenAPI UI is at `/api/docs`,
and the machine-readable spec is in [`openapi.json`](openapi.json).

## Authentication

| Client | Mechanism |
|---|---|
| Browser (desktop / mobile) | `POST /api/auth/login` sets `netops_session` (HttpOnly, SameSite=Strict, Secure on HTTPS) and `netops_csrf`. Every non-GET request must send `X-CSRF-Token`. |
| MCP / Codex / CLI / scripts | `Authorization: Bearer nop_…` (API token from Settings > API tokens). Optional `X-Client-Interface: mcp|cli|codex` for the audit log. |

Errors are JSON: `{"detail": "...", "error_type": "ValidationError|PolicyError|RoleError|ModeError|RateLimitError|..."}`.
Status codes: 401 not authenticated · 403 policy/role/CSRF · 409 mode or scan state · 422 invalid input · 429 rate limited (`Retry-After`).

## Endpoints

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/api/health` | public | status, version, `setup_required`, mode |
| POST | `/api/auth/setup` | public, localhost, once | create first administrator |
| POST | `/api/auth/login` · `/api/auth/logout` | — | session sign in / out |
| GET | `/api/auth/me` | any | current user, CSRF token, mode, AI status |
| POST | `/api/auth/change-password` | any | change own password (revokes sessions) |
| GET/POST/DELETE | `/api/auth/tokens[/{id}]` | operator | list / create / revoke API tokens |
| POST | `/api/diagnostics/{ping\|traceroute\|port\|dns\|http\|https\|ssl}` | operator | run a diagnostic |
| GET | `/api/history[?type&target&status&mode&limit&offset]` · `/api/history/{ref}` | viewer | diagnostic history |
| GET | `/api/dashboard` | viewer | widgets and observations |
| POST | `/api/assistant/message` `{message, pending?}` | viewer* | natural-language assistant (*execution needs operator) |
| POST | `/api/discovery/plan` | operator | validate and plan a range; nothing is sent |
| POST | `/api/discovery/{ref}/start` `{confirm:true}` | operator | execute a planned scan |
| POST | `/api/discovery/{ref}/control` `{action: pause\|resume\|cancel}` | operator | control a running scan |
| GET | `/api/discovery` · `/api/discovery/{ref}` · `/{ref}/comparison` · `/{ref}/changes` | viewer | scans, devices, classification, diff vs previous scan |
| POST | `/api/discovery/devices/{id}/register` · `/api/comparisons/{id}/review` | operator | register a discovered device / mark reviewed |
| GET/POST | `/api/inventory` · GET/PUT/DELETE `/api/inventory/{id}?confirm=<Server ID>` | viewer / operator | inventory CRUD |
| GET | `/api/inventory/export` · POST `/api/inventory/import?dry_run=` | viewer / operator | Excel export / import |
| GET/POST | `/api/reports` · GET `/api/reports/types` · GET `/api/reports/{ref}/download[?inline=true]` | viewer / operator | reports |
| GET/PUT | `/api/settings` | viewer / admin | runtime settings |
| GET | `/api/system` | viewer | host OS, available tools, source network |
| GET | `/api/policy` · POST/PUT/DELETE `/api/policy/networks[/{id}]`, `/api/policy/domains[/{id}[/confirm]]`, `/api/policy/ports[/{port}]` | viewer / admin | approved targets |
| GET/PUT | `/api/ai` | viewer / admin | Claude configuration (the key is write-only) |
| GET/POST/PUT | `/api/users[/{id}]` | admin | users and roles |
| GET | `/api/audit` · `/api/audit/export` | admin | audit trail (CSV export is formula-injection safe) |
| GET/POST | `/api/maintenance/backups` · POST `/api/maintenance/restore` `{file_name, confirm}` · POST `/api/maintenance/retention` | admin | backup, restore, retention |

### Diagnostic request bodies

```jsonc
// ping
{"target": "10.1.57.10", "count": 4, "timeout": 2}
// traceroute
{"target": "10.1.57.10", "max_hops": 20}
// port — one or many approved ports, or "approved" for all
{"target": "10.1.57.15", "ports": [3306, 443]}
// dns — A AAAA CNAME MX NS TXT PTR (an IP implies PTR)
{"domain": "emgs.com.my", "record_type": "MX", "dns_server": "10.1.57.2"}
// http / https
{"url": "https://visa.emgs.com.my/"}
// ssl
{"target": "visa.emgs.com.my", "port": 443}
```

### Diagnostic response (all types)

```json
{
  "reference": "DX-20261126-ABD4D7", "type": "ping", "target": "10.1.57.15", "port": null,
  "status": "completed", "outcome": "No ICMP response", "mode": "live", "simulated": false,
  "source_interface": "web", "source_network": "LAPTOP-01 (10.1.20.45)", "user": "admin",
  "started_at": "2026-11-26T05:45:10Z", "duration_ms": 4012.4, "parameters": {"count": 4, "timeout": 2},
  "result": {"target_ip": "10.1.57.15", "packets_transmitted": 4, "packets_received": 0, "packet_loss_pct": 100.0, "...": "..."},
  "assessment": {"summary": "...", "confirmed": ["..."], "possible_causes": ["..."], "recommendations": ["..."], "severity": "warning", "source": "rules"},
  "raw_output": "PING 10.1.57.15 ...", "error": ""
}
```

Outcome values: **ping** Successful · Partial packet loss · No ICMP response · DNS resolution failure · Execution error.
**traceroute** Destination reached · Destination did not respond. **port** Open · Closed · Filtered / timed out ·
Unreachable · All/Some/None open. **ssl** Valid · Expiring soon · Expired · Hostname mismatch · Untrusted.

## WebSockets

| Path | Purpose |
|---|---|
| `/ws/scans/{ref}` | streams `snapshot`, `progress {processed,total,responding}`, `status`, `finished` |
| `/ws/console` | send `{"type":"run","diagnostic":"ping","params":{...},"csrf":"<token>","id":1}` → `started`, `result` or `error` |

WebSockets authenticate with the session cookie, reject a foreign `Origin` header, and console commands must include the CSRF token.
