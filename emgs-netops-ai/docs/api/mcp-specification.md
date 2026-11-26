# MCP and Codex integration

`mcp-server/server.py` is a stdio MCP server. Its tools call the **same service layer** as the dashboard, so validation,
approved-target policy, roles, rate limits, timeouts, history and audit are identical. No tool runs arbitrary commands,
and there is no shell, exec or "run command" tool.

## Identity

The server authenticates with an **API token** (`NETOPS_API_TOKEN`) that belongs to a dashboard user:

* Create one in the dashboard: *Settings > API tokens > Create token*. It is shown once and expires after 90 days.
* Or from a terminal: `python scripts/netops_cli.py create-token --username <you>` (prompts for your password).
* Executions are audited under that user with interface `mcp` (or `cli`). Revoking the token cuts access immediately.

## Tools

| Tool | Arguments | Returns |
|---|---|---|
| `run_ping` | `target`, `count=4` | packets, loss, min/avg/max latency, outcome, assessment |
| `run_traceroute` | `target`, `max_hops=20` | hops (ip, hostname, times, timeouts), destination_reached |
| `check_tcp_port` | `target`, `port` | open / closed / filtered, response time |
| `check_multiple_ports` | `target`, `ports?` (omit = all approved) | per-port results |
| `check_dns` | `domain`, `record_type=A`, `dns_server?` | values, DNS server, query time |
| `check_http` / `check_https` | `url` | status code, redirects, TLS version, timing |
| `check_ssl_certificate` | `host`, `port=443` | subject, issuer, validity, days remaining, hostname match |
| `scan_ip_range` | `ip_range`, `confirm=false`, `plan_reference?`, `wildcard_octets?` | **step 1** plan; **step 2** (confirm + plan_reference) devices, ports, inventory comparison |
| `discover_hosts` | `ip_range?`, `confirm?`, `plan_reference?`, `scan_reference?` | compact live-host list |
| `get_server_inventory` | `status?`, `category?` | servers (sample rows flagged) |
| `search_server_inventory` | `query` | matching servers |
| `get_diagnostic_history` | `diagnostic_type?`, `target?`, `limit=20` | recent executions |
| `generate_diagnostic_report` | `report_type`, `format=pdf\|xlsx`, `target?`, `scan_reference?`, `days=30` | reference, file path, download URL |

Every tool returns JSON with `ok: true|false`. Failures include `error` and `error_type` (for example `PolicyError`
"10.9.9.9 is not inside an approved network"), which lets the AI explain the refusal instead of retrying.
Raw command output is truncated to 3,000 characters to keep the client's context small.

Discovery is deliberately **two-step**. The server's instructions tell the AI client to show the user the plan (address
count, ports) and to confirm only after the user agrees.

## Claude Code

```bash
# from the project folder, after the first start (so .venv exists)
claude mcp add emgs-netops \
  --env NETOPS_API_TOKEN=nop_xxxxxxxx \
  -- "$(pwd)/.venv/bin/python" "$(pwd)/mcp-server/server.py"
```

Windows (PowerShell): use `.venv\Scripts\python.exe` and `mcp-server\server.py` with absolute paths.
For a project-scoped setup, copy `mcp-server/mcp.json.example` to `.mcp.json` and fill in the paths and token (do not
commit the token). Then ask Claude Code, for example: *"Check whether port 3306 is accessible on 10.1.57.15"*.

## Codex

Codex supports MCP servers through its own configuration, not Claude's. Register the same server in `~/.codex/config.toml`:

```toml
[mcp_servers.emgs-netops]
command = "/absolute/path/emgs-netops-ai/.venv/bin/python"
args = ["/absolute/path/emgs-netops-ai/mcp-server/server.py"]
env = { NETOPS_API_TOKEN = "nop_xxxxxxxx" }
```

Recent Codex CLIs can do the same with `codex mcp add emgs-netops --env NETOPS_API_TOKEN=nop_… -- <python> <server.py>`.
Run `codex mcp --help` to confirm the syntax for your installed version.

If MCP is unavailable in your Codex environment, use the **CLI tool interface**. It prints JSON and has the same safety
properties:

```bash
export NETOPS_API_TOKEN=nop_xxxxxxxx
.venv/bin/python scripts/netops_cli.py ping 10.1.57.10
.venv/bin/python scripts/netops_cli.py port 10.1.57.15 3306 443
.venv/bin/python scripts/netops_cli.py dns emgs.com.my --type MX
.venv/bin/python scripts/netops_cli.py scan 10.1.57.0/24                 # plan
.venv/bin/python scripts/netops_cli.py scan 10.1.57.0/24 --confirm DS-…  # execute
.venv/bin/python scripts/netops_cli.py report discovery --format xlsx
```

Add a line to the repository's `AGENTS.md` so Codex knows the tool exists, e.g. *"For network diagnostics use
`scripts/netops_cli.py` (JSON output). Never run ping/nmap directly."*

## Verification

`backend/tests/test_mcp_cli.py` starts the real server over stdio with the MCP client SDK. It checks that the 14 tools
above are listed, that no command-execution tool exists, and that calls go through policy. The same file tests the CLI.
