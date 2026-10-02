# ADR 0004 — Deterministic analytics, LLM for language

**Status:** accepted

Numbers are computed by deterministic code (SQL aggregation, decomposition, OLS, exponential smoothing). The LLM maps language to plans and rewrites computed facts into prose. A grounding check rejects any narrative containing a number absent from the facts; the deterministic narrative is used instead. Without an API key the platform runs a catalog-driven deterministic planner, and every response states which planner and narrator ran. Default model: `claude-opus-5-5` with server-side refusal fallbacks enabled.
