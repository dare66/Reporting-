<?php

namespace App\Domain\Query;

use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs compiled queries on the read-only analytical connection with a
 * statement timeout, row cap, permission-aware cache and audit trail.
 */
final class QueryExecutor
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $semanticQuery  echoed into the result for evidence */
    public function execute(CompiledQuery $compiled, SecurityContext $security, array $semanticQuery = [], bool $useCache = true): QueryResult
    {
        $cacheKey = 'q:'.$security->organisationId.':'.hash('sha256', $compiled->hash().$security->fingerprint());
        $ttl = (int) config('aixbi.query.cache_ttl');

        if ($useCache && $ttl > 0 && ($hit = $this->cacheGet($cacheKey))) {
            $this->auditQuery($compiled, $hit['duration_ms'], count($hit['rows']), true);

            return new QueryResult($compiled->columns, $hit['rows'], $compiled->sql, $compiled->hash(), $hit['duration_ms'], true, $hit['truncated'], $hit['executed_at'], $semanticQuery);
        }

        $started = hrtime(true);
        try {
            $rows = $this->run($compiled);
        } catch (Throwable $e) {
            $this->audit->record('query.run', [
                'result' => 'failure', 'query_hash' => $compiled->hash(), 'query_sql' => $compiled->sql,
            ], ['error' => substr($e->getMessage(), 0, 500)]);
            throw new QueryExecutionException($this->friendlyError($e), previous: $e);
        }
        $duration = (int) ((hrtime(true) - $started) / 1e6);

        $truncated = count($rows) > $compiled->limit;
        $rows = array_slice($rows, 0, $compiled->limit);
        $rows = array_map(fn ($r) => $this->normaliseRow((array) $r, $compiled->columns), $rows);
        $executedAt = now()->toIso8601String();

        if ($useCache && $ttl > 0) {
            $this->cachePut($cacheKey, ['rows' => $rows, 'truncated' => $truncated, 'duration_ms' => $duration, 'executed_at' => $executedAt], $ttl);
        }
        $this->auditQuery($compiled, $duration, count($rows), false);

        return new QueryResult($compiled->columns, $rows, $compiled->sql, $compiled->hash(), $duration, false, $truncated, $executedAt, $semanticQuery);
    }

    /** @return array<int, object> */
    private function run(CompiledQuery $compiled): array
    {
        $connection = DB::connection(config('aixbi.query.connection'));
        $timeout = (int) config('aixbi.query.timeout_ms');

        return $connection->transaction(function () use ($connection, $compiled, $timeout) {
            // Defence in depth on top of the SELECT-only database role.
            $connection->statement('SET TRANSACTION READ ONLY');
            $connection->statement('SET LOCAL statement_timeout = '.max(100, $timeout));

            return $connection->select($compiled->sql, $compiled->bindings);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    private function normaliseRow(array $row, array $columns): array
    {
        foreach ($columns as $c) {
            $k = $c['key'];
            if (! array_key_exists($k, $row)) {
                continue;
            }
            if (in_array($c['role'], ['metric', 'measure'], true) && $row[$k] !== null) {
                $row[$k] = round((float) $row[$k], 6);
            }
        }

        return $row;
    }

    private function auditQuery(CompiledQuery $compiled, int $duration, int $rows, bool $cached): void
    {
        $this->audit->record('query.run', [
            'resource_type' => 'semantic_query',
            'query_hash' => $compiled->hash(),
            'query_sql' => $compiled->sql,
            'duration_ms' => $duration,
            'row_count' => $rows,
        ], ['cached' => $cached]);
    }

    /** @return array{rows: list<array<string, mixed>>, duration_ms: int, truncated: bool, executed_at: string}|null */
    private function cacheGet(string $key): ?array
    {
        try {
            return Cache::get($key);
        } catch (Throwable) {
            return null; // cache outage degrades to live queries, never to errors
        }
    }

    /** @param  array{rows: list<array<string, mixed>>, duration_ms: int, truncated: bool, executed_at: string}  $value */
    private function cachePut(string $key, array $value, int $ttl): void
    {
        try {
            Cache::put($key, $value, $ttl);
        } catch (Throwable) {
        }
    }

    private function friendlyError(Throwable $e): string
    {
        $msg = $e->getMessage();

        return match (true) {
            str_contains($msg, 'statement timeout') => 'The query took too long and was stopped. Try a shorter time range or fewer dimensions.',
            str_contains($msg, 'could not connect'), str_contains($msg, 'Connection refused') => 'The analytical data store is unreachable.',
            default => 'The query could not be completed.',
        };
    }
}
