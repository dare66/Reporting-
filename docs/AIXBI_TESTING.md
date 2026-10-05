# Testing

CI (`.github/workflows/ci.yml`) runs the API, AI-service and web gates on every push, and builds the container images. The browser journeys need a running stack and are run locally before each delivery; adding them to CI is planned. A feature counts as done only when its UI, API, data and security work together on real data, and a test proves it.

## Suites

| Suite | What it covers | How to run |
|---|---|---|
| **API** (PHPUnit, PostgreSQL) | 114 tests: compiler, expressions, time ranges, authentication and MFA, cross-tenant isolation, roles, row- and column-level security, analytics correctness against hand-written SQL, reporting, ingestion, Widget Studio, administration, Auto BI, metric store, Data Trust Center, safe source removal, SSRF guard | `cd apps/api && composer check` (Pint, Larastan level 6, tests) |
| **AI service** (pytest) | 27 tests: the analyst graph against a mocked API, planner, LLM guards (grounding, plan validation), forecasting and anomaly maths, governed-metric selection | `cd services/ai && ruff format --check . && ruff check . && mypy && python -m pytest -q` |
| **Web** (Karma) | 31 tests: formatting, chart specifications, pivot, Widget Studio model | `cd apps/web && npm run check` (Prettier, ESLint, production build, tests) |
| **Browser journeys** (Playwright, against a running stack) | 13 journeys, below | `cd tests/e2e && npm test` |

## Browser journeys

| Journey | Proves |
|---|---|
| `smoke.mjs` | Sign-in, home, AI question with evidence, investigation, report creation |
| `routes.mjs` | Every page opens for every role without errors |
| `studio.mjs` | Dashboard filters; Widget Studio builds, saves and reopens widgets; ratio metrics refuse running totals |
| `admin.mjs` | People, roles, security policy, a new person held until they choose a password, personal settings |
| `auto-bi.mjs` | A 3-sheet Excel workbook becomes a governed model, dashboard and report; every widget renders from the uploaded data |
| `metrics.mjs` | Changing a certified calculation lapses it; the approver cannot certify; a second person certifies; version diff; dashboards still render |
| `database.mjs` | Connecting a PostgreSQL database leads to choosing tables; three load with progress under a row limit; Auto BI designs from them, joined (needs `SOURCE_DB_*` settings) |
| `crossfilter.mjs` | Clicking a bar filters the other widgets, shows as a removable filter, and clearing it restores every value |
| `projects.mjs` | A new project starts empty; switching to it from the top bar reopens the page inside it; an upload there is not in General; the choice survives a reload |
| `actions.mjs` | Test 6: “Create an incident for the biggest issue” proposes one with evidence; nothing opens until it is approved; approval opens and verifies it; it is worked to resolved; asking again does not duplicate; a viewer has no access |
| `mobile.mjs` | Test 10: at 390 px, home, a dashboard, a report, the AI analyst and Actions fit the screen with no sideways scrolling; charts full width; bottom navigation and More menu work |
| `trust.mjs` | A reload with a broken schema is caught, with its impact; acknowledgement; removing a source shows its impact and cleans up |

Each journey fails on any uncaught page error, console error or 5xx response, and cleans up the data it creates.

## Test data

- **Demo tenant:** EMGS, with 290,000 synthetic applications and the related payments, institutions and countries. The test suite uses a scaled-down copy.
- **Auto BI fixture:** `tests/e2e/fixtures/Student Applications.xlsx`, 2,393 applications across 3 related sheets, with real Excel date cells.
- **Drift fixtures:** generated in the tests themselves.

## Planned additions

| Area (brief §60) | Plan |
|---|---|
| Accessibility | axe-core checks inside the browser journeys, failing on serious violations |
| Visual regression | Screenshot comparison of key pages in light and dark themes |
| AI benchmark suite | Questions with expected governed answers (metric, filters, numbers), run on every prompt or model change; the regression gate for the AI gateway |
| Browser journeys in CI | Start the Docker stack in a CI job and run `npm test` in `tests/e2e` |
| Load | `infra/load/k6-smoke.js` in CI with thresholds from the brief (cached dashboard under 2 s, query under 3 s) |
