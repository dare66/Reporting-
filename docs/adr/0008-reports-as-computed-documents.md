# ADR 0008 — Reports are computed documents with immutable versions

**Status:** accepted

A report section stores its *blueprint* (what to compute) and its *content* (what was computed, with query hashes). Users may edit narrative; figures are always recomputed from queries. Versions snapshot the whole report; restore creates a new version (rollback is auditable). Exports render from content, so PDF, PPTX, XLSX and the web view always agree.
