# Installation and startup

## Requirements

| | Windows 10/11 | Linux (Ubuntu/RHEL) |
|---|---|---|
| Python | 3.11 or newer (python.org, tick *Add to PATH*) | 3.11+ (`python3`, `python3-venv`) |
| Node.js | 20+ (only to build the dashboard the first time) | 20+ |
| ping / tracert | built in | `iputils-ping`, `traceroute` or `iputils-tracepath`, `iproute2` |
| Nmap | optional | optional (`nmap`) |

Check a laptop with `python scripts/install/check_environment.py`.

## Start

```bash
./start_netops.sh            # Linux / macOS
start_netops.bat             # Windows
```

The script:
1. checks Python;
2. creates `.venv` and installs `backend/requirements.txt` (re-installs only when the file changes);
3. builds the dashboard into `frontend/dist` on first run (needs npm);
4. checks tools, configuration and the database, and applies migrations;
5. starts the backend on **http://127.0.0.1:8750**, which serves the dashboard and API;
6. prints the dashboard URL and the mobile-access instructions.

It never binds to all interfaces. Options: `--mobile` (adds the HTTPS listener, see mobile-access.md), `--dev`
(also runs the Vite dev server on 127.0.0.1:5173 with hot reload), `--port N`.

Open the dashboard, create the administrator (first run only), then:
1. **Settings > Approved targets**: add the real EMGS ranges, domains and ports confirmed with the network team.
2. **Settings > Operating mode**: switch to *Live* when you are on the EMGS network.
3. Optionally configure the Claude API key and create an API token for Claude Code / Codex.

## Stop

`./stop_netops.sh` / `stop_netops.bat`, or Ctrl+C in the window. Shutdown is clean (in-flight requests finish). A scan
running at shutdown is marked *failed* at the next start.

## Configuration (environment variables, optional)

| Variable | Default | Meaning |
|---|---|---|
| `NETOPS_DATA_DIR` | `./data` | database, reports, backups, secret key, certificates |
| `NETOPS_DB_PATH` | `data/database/netops.db` | database file |
| `NETOPS_PORT` | `8750` | backend port (mobile HTTPS uses port + 1) |
| `NETOPS_SESSION_HOURS` | `8` | bootstrap session length |
| `ANTHROPIC_API_KEY` | — | alternative to storing the Claude key in Settings |

Everything else (limits, approved targets, retention, paths, mode, AI) is set in the Settings screen.

## Backup

* Dashboard: *Settings > Backup* (create, restore with a safety copy, apply retention).
* Scheduled: `python scripts/backup/backup_netops.py`. Run it daily via Task Scheduler or cron; it is safe while the app is running.

## Desktop shortcut

* Windows: `powershell -ExecutionPolicy Bypass -File desktop\startup\Create-Shortcut.ps1`
* Linux: edit and copy `desktop/startup/emgs-netops-ai.desktop` to `~/.local/share/applications/`.

## Upgrading

Pull the new version and run the start script. Dependencies re-install automatically, migrations apply automatically,
and data in `data/` is kept. Delete `frontend/dist` to force a dashboard rebuild.
