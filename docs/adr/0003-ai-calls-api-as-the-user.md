# ADR 0003 — The AI service has no database access

**Status:** accepted

The AI service authenticates the browser's JWT and forwards it to the API for every read and action. It holds no database credentials (enforced by a Kubernetes NetworkPolicy). Consequently an AI answer can never contain data the user could not see in the UI, and every AI data access appears in the audit log as that user.
