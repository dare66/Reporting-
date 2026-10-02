# Contributing

AIXBI is three codebases with one set of standards: every change passes the
same gates locally and in CI, and the rules below explain the conventions
those gates cannot express.

## Quality gates

Run the check for the part you changed before pushing. CI runs exactly these.

| Codebase | One command | What it runs |
|---|---|---|
| API — `apps/api` | `composer check` | Pint (style) · Larastan level 6 (static analysis) · PHPUnit on PostgreSQL |
| AI service — `services/ai` | `ruff format --check . && ruff check . && mypy && pytest -q` | formatter · lint (incl. bandit security rules) · `mypy --strict` · tests |
| Web — `apps/web` | `npm run check` | Prettier · angular-eslint (incl. template accessibility) · strict-template build · unit tests |
| Whole stack | `cd tests/e2e && npm test` | smoke journey + every route as two personas (stack must be running) |

Fix formatting with `composer format`, `ruff format .` or `npm run format`.
Dependency audits (`composer audit`, `npm audit`) also run in CI and must be clean.

Suppressions (`@phpstan-ignore`, `# noqa`, `eslint-disable`, `as any`,
non-null `!`) are not used to make a gate pass. The one exception documents
its reason inline (`explore.ts`: route query-parameter aliases).

## Conventions

**Types describe real payloads.** API shapes live in
`apps/web/src/app/core/models.ts` and `core/ai-models.ts`; PHP array shapes are
named `@phpstan-type` aliases next to the code that produces them; Python uses
`JSON`/`JSONList` from `app/types.py` and validates API responses at the client
boundary. When a type and the data disagree, fix the type to match the data (and
handle the case), never the other way round.

**Validate once, at the boundary.** HTTP input, AI plans and stored JSON are
normalised where they enter (`SemanticQuery::fromArray`, `AixbiApi._object`),
so inner code can rely on exact shapes instead of re-checking defensively.

**Every write reports failure.** A user action either succeeds visibly or shows
the API's message; nothing fails silently. Use the component's error signal or
the shared `errorMessage()`.

**One implementation per behaviour.** Shared logic lives in `core/` (web),
`app/Domain/*` (API) or `app/agents/*` (AI) — for example `ReportExporter`,
`insightEvidence()`, `formatting.change_of()`.

**Accessible by construction.** Overlays use the `appScrim` directive
(click or Escape dismisses) with a CDK focus trap on the dialog; every
interactive element is keyboard-reachable; charts keep their table view.

**Templates stay declarative.** Bind nullable values once with
`@if (x(); as v)`, branch on discriminated unions with `@switch`, and move
anything computed into the component.

**AI output is grounded.** Prompts are versioned files in
`services/ai/app/agents/prompts/`. Facts given to the narrator are the only
source of numbers, and the grounding guard must keep passing.

**Security defaults are not negotiable.** Queries go through the semantic layer
(never free SQL), secrets are server-generated and revealed once, rate limits
are keyed by what is being protected, and tenancy scopes fail closed.

## Commits and pull requests

- One logical change per commit, with a message that explains *why*.
- Tests accompany behaviour changes and bug fixes (write the failing test first
  when fixing a bug).
- Update `docs/` when an API, a payload shape or an operational step changes.
