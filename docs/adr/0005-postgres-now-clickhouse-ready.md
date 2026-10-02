# ADR 0005 — PostgreSQL analytics today, ClickHouse-ready dialect

**Status:** accepted

The compiler targets a `Dialect` interface. `PostgresDialect` is used in this release (one database to operate; read-only role and separate connection for analytics). `ClickHouseDialect` generates `-If` combinator SQL and is unit-tested; an HTTP executor for ClickHouse and DuckDB for in-process file analytics are scheduled for Phase 10 scale-out. Apache Arrow transfer is planned for large result sets between services.
