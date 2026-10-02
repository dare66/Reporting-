# ADR 0006 — Angular web now, Flutter mobile next

**Status:** accepted

The web app is built mobile-first (12/8/4-column reflow, bottom navigation, bottom sheets, swipeable report reader, offline snapshot of the home briefing) so phones are served today. A dedicated Flutter app (push, biometrics, secure storage, deep links) is Phase 8 and consumes the same `/api/v1` and `/ai-api/v1` contracts — no backend changes are required.
