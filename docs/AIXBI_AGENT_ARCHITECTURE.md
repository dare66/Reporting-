# Agent architecture

The analyst is one LangGraph graph whose nodes are specialised agents sharing a governed context: the user's catalog (metrics, dimensions and members they are allowed to see) and the conversation state. Code: `services/ai/app/agents/graph.py`.

```
question ─▶ Semantic ─▶ Intent & Planner ─▶ Governance ─┬─▶ Execute ─▶ Visualisation ─▶ Narrative ─▶ answer + evidence
                                                         └─(refused)──────────────────────▶ Narrative
```

## The brief's agents, mapped to what exists

| Brief agent (§25) | Implemented as | Status |
|---|---|---|
| Analyst — "what happened?" | Intent + planner, then governed queries in *execute* | ✅ |
| Investigation — "why?" | *Execute* calls `/analysis/root-cause` (counterfactual attribution, onset detection) | ✅ |
| Forecast — "what will happen?" | *Execute* calls `/analysis/forecast` (backtested, with intervals); what-if via `/analysis/scenario` | ✅ |
| Semantic — "what does this metric mean?" | *Semantic* node loads the governed catalog; metric definitions, owners and status come from the metric store | ✅ |
| Governance — "is this allowed?" | *Governance* node refuses out-of-scope or unauthorised requests before anything runs; the API enforces permissions again | ✅ |
| Dashboard — "how should this be visualised?" | *Visualisation* node (rule-based chart choice); dashboards by conversation; Auto BI's dashboard designer | ✅ |
| Report — "create the report" | *Execute* calls `/reports/generate`; conversational report edits | ✅ |
| Data — "can I trust the data?" | Data Trust Center and drift monitoring exist in the API; the analyst does not yet cite trust scores in answers | 🟡 |
| Executive — "what does leadership need to know?" | Home briefing and insights are computed by the API, not by a graph agent | 🟡 |
| Data engineering — "how should this be prepared?" | Auto BI understanding and relationship discovery; no transformation agent (no Data Flow Studio yet) | ⏭ |
| Action — "what should happen next?" | Alerts and notifications only; no action engine with approval | ⏭ |

## Not built yet

- **Agent Studio (§67):** organisation-defined agents with allowed tools, actions, models and approval rules. The `ai_agents` and `prompts` tables exist for this but are not yet used to configure behaviour.
- **Action engine (§41–42):** approved actions (ticket, webhook, Teams or Slack message) with an approval step and audit. Planned as the next AI-facing increment, because Test 6 of the brief depends on it.
- **MCP interface (§40):** exposing governed tools to external agents. It must reuse the same "call the API as the user" rule.
