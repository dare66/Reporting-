# AI architecture

The detailed design of the analyst (graph, intents, planners, grounding, statistics) is in [AI.md](AI.md). This document records the principles, what is built against the brief, and what is not.

## Principles (enforced in code)

1. **The AI never touches data directly.** The AI service holds no database credentials. Every number comes from a governed API call made *as the asking user*, so tenant isolation, row-level security, column rules and permissions all apply before the model sees anything ([ADR 0003](adr/0003-ai-calls-api-as-the-user.md)).
2. **Deterministic first.** Without an API key the analyst still works, using a rule-based planner and computed narratives. With Claude, plans are validated against the catalog and narratives must pass a grounding check ([ADR 0004](adr/0004-deterministic-first-ai.md)).
3. **Every number is evidence-backed.** Answers carry query hashes. The run stores the question, intent, planner, model, trace, evidence, tokens, cost and latency (`ai_runs`), and the UI's *Evidence* drawer shows them.
4. **Only governed metrics.** The analyst never chooses a deprecated metric, and prefers a certified one when two match a question equally well.
5. **External content is data, not instructions.** Uploaded files and source data are profiled and queried, never executed or passed to the model as instructions. Workbook formulas are never recalculated.

## Brief coverage

| Brief section | Status | Notes |
|---|---|---|
| §29 Natural-language BI | ✅ | Overview, trend, breakdown, why, forecast, what-if, report, alert and dashboard intents, with follow-ups that use the conversation context |
| §55 Evidence-first AI | ✅ | Per-answer evidence and query hashes, persisted and viewable |
| §56 AI security | ✅ | Security filtering happens before the AI sees data; numbers are grounding-checked; plans are validated against the catalog |
| §59 Cost tracking | 🟡 | Tokens, cost and latency per run, viewable in Governance → AI usage. No token budgets or per-tenant limits yet (only an AI rate limit of 30 requests a minute per user) |
| §26 AI gateway (multi-provider routing) | ⏭ | Today: Anthropic (`AIXBI_MODEL`, default `claude-opus-5-5`) plus the deterministic planner. Next: a provider interface with task-based routing (planning, narrative, investigation) and a "sensitive data → approved private model" rule |
| §27 Evaluation lab | 🟡 | 27 tests cover the graph, the planner, LLM guards and the statistics. A benchmark set of questions with expected governed answers, run on every model or prompt change, is not built yet |
| §28 Organisational memory | 🟡 | Conversations persist, and metric definitions and synonyms are shared and governed. Approved interpretations and terminology memory are not built |
| §30 Ask about this dashboard | 🟡 | The analyst accepts dashboard context; a conversation panel embedded in every dashboard is not built |
| §50 Voice BI | ⏭ | |

## Configuration

| Variable | Effect |
|---|---|
| `ANTHROPIC_API_KEY` | Turns on the Claude planner and narrator; without it everything still works deterministically |
| `AIXBI_MODEL` | Model id (default `claude-opus-5-5`) |
| `AI_SERVICE_TOKEN` | Shared secret for the API's internal calls to the analytics engine |
| `LANGFUSE_PUBLIC_KEY` / `LANGFUSE_SECRET_KEY` | Turn on Langfuse tracing |
