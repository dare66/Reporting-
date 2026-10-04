# Data flow architecture

> **Status: design only. Data Flow Studio is not built.** This document describes what exists today and the agreed design for the studio. Nothing here is presented in the product as working.

## Today

Data enters through a connector and is loaded as-is into a typed table in the `analytics` schema (see [AIXBI_CONNECTOR_ARCHITECTURE.md](AIXBI_CONNECTOR_ARCHITECTURE.md)). Preparation that happens automatically:

- type inference (integer, decimal, boolean, date, timestamp, text) from a sample of 2,000 records;
- column-name sanitising and empty-heading removal;
- one dataset per workbook sheet;
- profiling, trust scoring and drift detection on every load.

There is no user-defined cleaning, joining or aggregation step before the semantic layer. Joins happen *in* the semantic layer at query time, via relationships, which Auto BI discovers and verifies against the data.

## Design for Data Flow Studio (brief §17)

A flow is a versioned, directed graph of typed nodes, stored as JSON and compiled to SQL that runs inside the analytical database. Data never passes through the browser.

| Node family | Nodes | Compiles to |
|---|---|---|
| Input | Source (dataset) | `SELECT … FROM` |
| Shape | Select, Rename, Calculate, Clean (trim, case, cast), Mask | Projection expressions |
| Filter | Filter, Deduplicate, Sample, Validate | `WHERE`, `DISTINCT ON`, `TABLESAMPLE`, rejected-rows table |
| Combine | Join, Union, Lookup | `JOIN`, `UNION ALL` |
| Aggregate | Group, Aggregate, Pivot, Unpivot | `GROUP BY`, `FILTER`, `LATERAL VALUES` |
| Output | Output dataset | `CREATE TABLE … AS` into the analytics schema, then registered (profiled, trust-scored, drift-monitored) |

Rules the design commits to:

- Every node's output schema is computed before running, so the canvas shows the columns available at each step.
- Expressions use the same restricted parser approach as metric expressions: no raw SQL from users.
- A flow's output dataset carries lineage back to its inputs, so the Trust Center's impact analysis extends through flows.
- The AI may *propose* a flow from a sentence ("clean this, join payments to students, remove duplicates"). It appears on the canvas for review and never runs unapproved.

Prerequisites: streamed ingestion and the connector interface, so flows can read large sources without loading them into PHP memory.
