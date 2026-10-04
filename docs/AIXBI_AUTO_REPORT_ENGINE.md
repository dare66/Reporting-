# Report engine

Reports are computed documents ([ADR 0008](adr/0008-reports-as-computed-documents.md)): a list of section *blueprints* (what to show) that `ReportBuilder` turns into content from governed queries. A report can therefore be refreshed, versioned and exported without anyone retyping numbers.

## Sections (built)

| Type | Computes |
|---|---|
| `kpis` | KPI cards with the change against the comparable prior period |
| `chart` | A metric's trend at a chosen grain |
| `breakdown` | A metric ranked by a dimension |
| `anomalies` | Statistically significant anomalies in the last 45 days |
| `root_cause` | Ranked drivers of a metric's change, with when it started |
| `forecast` | A backtested projection with intervals |
| `summary`, `risks` | Written from the facts the other sections computed, never from free text |
| `text` | A person's own words |

Every computed section keeps the query hashes behind its numbers as evidence.

## How reports are made

- **From a template.** Nine templates are seeded (CEO, operations, finance and others). Organisations can add their own with `POST /report-templates`.
- **By the AI analyst.** "Create a monthly management report" plans the sections, and conversational edits change them.
- **By Auto BI.** Uploading or connecting data designs an executive report automatically. It reports on the latest period the data covers, so an older extract still gets a meaningful report. See [AIXBI_AUTO_BI_ENGINE.md](AIXBI_AUTO_BI_ENGINE.md).

## Lifecycle and delivery (built)

- **Lifecycle:** draft → published → archived.
- **Versions:** immutable snapshots, which can be compared side by side and restored.
- **Exports:** PDF (with server-rendered charts), PPTX (native, editable charts), XLSX (one sheet per section, plus provenance), CSV and standalone HTML. Large exports run in the background.
- **Schedules:** reports can go out by email, push or in-app notification.

## Not built yet (brief §14–16, §51–53)

| Capability | Status |
|---|---|
| DOCX export | ⏭ The `Exporter` interface makes this an additional class; it needs a server-side Word writer and a test that opens the file. |
| Template intelligence: upload an existing report (PDF/DOCX), detect its layout and branding, and map live data into it | ⏭ |
| Drag-and-drop Report Studio with page breaks, groups, subtotals and conditional formatting | 🟡 Sections can be added, reordered, edited and refreshed in the report view; page layout controls are missing |
| AI design critic (critique and improve a generated report) | ⏭ |
| Report Modernization Assistant for legacy reporting servers | ⏭ Needs the reporting-server connector first |
