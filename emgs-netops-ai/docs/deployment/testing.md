# Testing

| Suite | Command | Covers |
|---|---|---|
| Backend (pytest) | `cd backend && ../.venv/bin/python -m pytest -q` | parsers (Windows/Linux ping, tracert, traceroute, tracepath, ARP, Nmap XML), validation and policy, every diagnostic outcome, Live adapters (real sockets, no-shell execution), discovery (plan/confirm, unapproved/invalid/oversized/public ranges, ICMP-blocked host, unknown hostname, MAC unavailable, pause/resume, cancel, one-at-a-time, WebSocket progress), comparison classes, inventory CRUD and Excel import/export, all 9 reports × 2 formats, assistant flows, auth/CSRF/roles/tokens/lockout/expiry/rate limits, audit, backup/restore/retention, MCP over stdio, CLI |
| Frontend unit (Vitest) | `cd frontend && npm test` | API client (CSRF, errors, unreachable laptop, session expiry), status tones, assessment rendering |
| End-to-end (Playwright) | `cd frontend && npx playwright test --trace off` | first-run setup, dashboard quick diagnostic, policy rejection, assistant clarification flow, discovery plan → confirm → comparison → Excel, inventory, reports, settings, audit; phone viewport: bottom navigation, quick ping, assistant, mobile interface recorded in history, session expiry |

The E2E suite starts its own instance in Simulation mode on port 8760 with a throwaway data directory (`E2E_DATA_DIR`).

## Manual checks still required on the target laptop

These depend on the real environment and can't be automated in CI:

1. **Live mode on the EMGS network**: ping, traceroute, port and discovery against an approved range (Windows `ping`/`tracert` output is parsed from captured fixtures; confirm on a Windows laptop).
2. **Mobile over VPN/Tailscale**: start with `--mobile`, open from the phone, run a ping, then disconnect the VPN mid-session to see the "Cannot reach the NetOps laptop" message.
3. **Claude API**: set a key, then confirm the assistant shows the *Claude* badge and explanations; remove the key and confirm everything still works.
4. **Claude Code / Codex**: register the MCP server with a token and run `check_tcp_port`.
