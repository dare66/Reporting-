# Run AIXBI on your PC with Docker

Built by Scicom (MSC) Berhad for EMGS.

## What you need

- **Docker Desktop** (Windows or macOS) or Docker Engine with the Compose plugin (Linux).
  On Windows, use the WSL 2 backend (the Docker Desktop default).
- About **8 GB of free RAM for Docker** and **6 GB of disk**.
- An internet connection for the first build, which downloads base images and packages.

## Start

1. Unzip the folder anywhere, for example `C:\aixbi` or `~/aixbi`.
2. Start it:
   - **Windows:** double-click `start.bat`.
   - **macOS / Linux:** run `./start.sh` in a terminal.
   - **Or, anywhere:** run `docker compose up -d --build` in the folder.
3. Open **<http://localhost:8080>**.

The first start takes **5–15 minutes**. It builds the images, creates the database and seeds a realistic demo
organisation. After that, starts take about 20 seconds.

## Sign in

Every demo account uses the password **`Demo@2026!`**.

| Email | What you'll see |
|---|---|
| `admin@emgs.demo` | Everything, including Administration (people, roles, security policy) |
| `ceo@emgs.demo` | The executive Command Centre |
| `analyst@emgs.demo` | Explore, Widget Studio, reports, the AI analyst |
| `manager.asia@emgs.demo` | Row-level security: Asian markets only |
| `engineer@emgs.demo` | Data platform and semantic model |
| `viewer@emgs.demo` | Read-only |

## Bring in your own data

- **Excel, CSV or JSON:** open **Data** and drop the file. AIXBI reads every sheet and opens Auto BI, which explains the data, proposes KPIs, and designs a dashboard and an executive report for you to approve.
- **A database (PostgreSQL, MySQL, MariaDB):** open **Data → PostgreSQL** (or MySQL) and enter the connection details. AIXBI lists the tables, loads the ones you choose in the background, and opens Auto BI on them.
  - A database on **this computer**: use `host.docker.internal` as the host, not `localhost`. Inside Docker, `localhost` means the AIXBI container itself.
  - A database elsewhere on your network: use its normal host name or IP address.
- Add as many sources as you like. Each one gets its own data model, dashboard and report.

## Everyday commands

| Task | Command |
|---|---|
| Stop (data is kept) | `stop.bat` / `./stop.sh`, or `docker compose down` |
| Start again | `start.bat` / `./start.sh`, or `docker compose up -d` |
| See logs | `docker compose logs -f api web ai` |
| Reset everything (deletes all data) | `docker compose down -v`, then start again |
| Update after pulling new code | `docker compose up -d --build` |

## Settings

Edit `.env`, which is created from `.env.example` on first start:

- `AIXBI_PORT`: change it if port 8080 is already taken.
- `ANTHROPIC_API_KEY`: optional; turns on the Claude planner and narrator in the AI analyst.
- Secrets and database passwords: change them before exposing the app beyond your PC.

## What runs

| Service | Role |
|---|---|
| `web` | nginx: the Angular app, and the gateway for `/api` and `/ai-api` (the only open port) |
| `api` | Laravel API (PHP-FPM) |
| `worker` | Background jobs (report exports) |
| `scheduler` | Scheduled reports, alerts, anomaly scans and insights |
| `ai` | FastAPI and LangGraph AI analyst, forecasting and anomaly engine |
| `postgres` | Metadata and the analytical demo data |
| `redis` | Cache and queues |
| `setup` | One-off: migrates the database and seeds the demo data on first start, then exits |

## Troubleshooting

- **"Port is already allocated":** set `AIXBI_PORT=8090` in `.env` and start again.
- **The page loads but shows no data:** first-time setup is still seeding.
  Check with `docker compose logs setup`; it ends with "Ready."
- **The build fails while downloading:** check your internet or proxy, then run `docker compose build --no-cache`.
- **Forecasts say "analytics engine unavailable":** the `ai` service is starting or stopped.
  Check with `docker compose ps` and `docker compose logs ai`.
