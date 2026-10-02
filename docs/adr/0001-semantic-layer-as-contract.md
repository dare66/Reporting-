# ADR 0001 — The semantic layer is the only path to data

**Status:** accepted

**Context.** The brief requires that AI never invents data and never runs unrestricted SQL, and that every statement is traceable.

**Decision.** All data access — UI, dashboards, reports, alerts, AI agents — goes through the semantic query API. LLMs produce *plans* over catalog keys, which are validated and compiled server-side. There is no "free SQL" endpoint.

**Consequences.** RLS, CLS, tenancy, caching and auditing are enforced in one place. Questions that the model cannot express are refused with an explanation instead of guessed. Adding a capability means extending the semantic model, not prompting harder.
