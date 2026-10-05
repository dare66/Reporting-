# Connector architecture

Connectors only *produce rows*. Everything after that is shared by every source: typing, loading into the analytical store, profiling, trust scoring, drift detection, Auto BI and the semantic layer. Adding a connector therefore never touches the rest of the platform.

```
connector (Connectors.php)  ─▶  TabularIngestor  ─▶  DatasetRegistrar  ─▶  DriftMonitor
  test · pull · readSheets       infer types,         introspect, profile,    snapshot, drift,
                                 load table           trust                   notify
```

## Working connectors

| Connector | Test connection | Load | Notes |
|---|---|---|---|
| PostgreSQL, MySQL, MariaDB | ✅ lists tables and views with row estimates | ✅ **all chosen tables at once, in the background**, up to a row limit per table (newest rows first when a table has a date column); then straight into Auto BI. **Incremental reloads**: only rows after the newest date already loaded are fetched and appended | Read-only session; rows are streamed, not held in memory; credentials encrypted at rest |
| CSV, TSV, text | — | ✅ upload (up to 50 MB) | Separator (comma, semicolon, tab, pipe) and encoding (UTF-8, UTF-16, Windows-1252) detected |
| Excel (.xlsx, .xls) | — | ✅ upload, **every sheet** becomes a dataset | Dates keep their type; formulas are never recalculated |

For CSV and Excel alike, the header is found below any title rows. Blank headings become "Column N" and repeated ones are numbered. Repeated header rows and total or subtotal rows are dropped, so sums are not doubled. Formatted values such as "RM 1,234.50", "(500)", "12%" and "05/03/2024" are read as numbers and dates. Dates are read day first unless only month first fits every value. Uploading a file with the same name again refreshes its source.
| JSON | — | ✅ upload | Records found at the root or under `data`, `records` or `items` |
| REST API | ✅ | ✅ | Optional auth header and records path; redirects not followed |
| Webhook | — | ✅ append | A server-generated secret URL, shown once |

The catalogue also lists SQL Server, Oracle, MongoDB, ClickHouse, GraphQL, S3 and Kafka, marked **planned**. The API refuses to connect them. They are never presented as working.

## Safety

- **Outbound guard** (`OutboundGuard`): database hosts and API URLs that resolve to loopback, link-local or cloud-metadata addresses are refused. Private networks stay reachable for on-premise databases; set `CONNECTORS_BLOCK_PRIVATE=true` to refuse them too.
- Uploaded workbooks are read by extension (never sniffed), as values only.
- **Removing a source** first shows its impact, and is refused while dashboards, reports, alerts or other models use its data. It drops only tables AIXBI created.

## Target interface (brief §37) — not built yet

The next step turns the `match` statements in `Connectors.php` into one class per connector behind a common interface:

```php
interface Connector
{
    public function test(DataSource $s): ConnectionTest;
    /** @return list<TableRef> */
    public function discover(DataSource $s): array;          // schemas and tables
    public function columns(DataSource $s, TableRef $t): array;
    public function preview(DataSource $s, TableRef $t, int $rows = 50): array;
    public function pull(DataSource $s, TableRef $t, ?Watermark $since = null): iterable; // streamed, incremental when supported
    public function capabilities(): Capabilities;            // incremental, cdc, streaming
}
```

Priorities, in order:
1. Streamed ingestion, so large tables do not load into memory.
2. Watermark (incremental) sync.
3. SQL Server, then Oracle, S3 and ClickHouse.
4. CDC and streaming (Kafka), and the reporting-server adapter for the Report Modernization Assistant.

Each connector ships only with a test against a real instance of the source, for example a Docker container in CI.
