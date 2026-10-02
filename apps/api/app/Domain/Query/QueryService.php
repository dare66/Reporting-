<?php

namespace App\Domain\Query;

use App\Domain\Query\Dialect\Dialect;
use App\Domain\Semantic\Catalog;
use App\Domain\Semantic\CatalogRepository;
use App\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Single entry point for semantic queries from controllers, AI agents and jobs. */
final class QueryService
{
    public function __construct(
        private readonly CatalogRepository $catalogs,
        private readonly Dialect $dialect,
        private readonly QueryExecutor $executor,
    ) {}

    public function run(string $modelKeyOrId, SemanticQuery $query, User $user, bool $useCache = true): QueryResult
    {
        $catalog = $this->catalogs->get($modelKeyOrId);

        return $this->runOn($catalog, $query, $user, $useCache);
    }

    public function runOn(Catalog $catalog, SemanticQuery $query, User $user, bool $useCache = true): QueryResult
    {
        $security = SecurityContext::forUser($user, $catalog);
        $compiled = $this->compiler()->compile($this->resolveRankings($catalog, $query, $user, $useCache), $catalog, $security);

        // Evidence records the question as asked (e.g. "top 10"); the SQL shows the members it resolved to.
        $result = $this->executor->execute($compiled, $security, ['model' => $catalog->key] + $query->toArray(), $useCache);

        return $query->grain === null ? $result : $result->withPartialFrom(self::currentBucketStart($query->grain));
    }

    /** Start of the time bucket that contains today — that bucket is incomplete. */
    public static function currentBucketStart(string $grain): string
    {
        $now = CarbonImmutable::now();

        return match ($grain) {
            'day' => $now->toDateString(),
            'week' => $now->startOfWeek()->toDateString(),
            'month' => $now->startOfMonth()->toDateString(),
            'quarter' => $now->startOfQuarter()->toDateString(),
            'year' => $now->startOfYear()->toDateString(),
            default => throw new InvalidArgumentException("Unsupported grain {$grain}"),
        };
    }

    /**
     * Distinct values of a dimension that the user may see, for filter pickers.
     * Runs as a governed query, so row-level security limits the choices and
     * sensitive dimensions stay denied without the data.sensitive permission.
     *
     * @return list<string|int|float|bool>
     */
    public function members(Catalog $catalog, string $dimension, ?string $search, int $limit, User $user): array
    {
        $catalog->dimension($dimension);
        $counts = array_filter($catalog->measures, fn ($m) => $m['aggregation'] === 'count' && $m['filters'] === []);
        $measure = array_key_first($counts) ?? array_key_first($catalog->measures)
            ?? throw new QueryValidationException("Model '{$catalog->name}' has no measures.");

        $query = new SemanticQuery(
            metrics: [],
            dimensions: [$dimension],
            filters: $search !== null && $search !== '' ? [['dimension' => $dimension, 'op' => 'contains', 'value' => $search]] : [],
            sort: [['key' => $dimension, 'dir' => 'asc']],
            limit: $limit,
            measures: [$measure],
        );

        return array_values(array_filter(array_column($this->runOn($catalog, $query, $user)->rows, $dimension), fn ($v) => $v !== null));
    }

    public function explain(Catalog $catalog, SemanticQuery $query, User $user): CompiledQuery
    {
        return $this->compiler()->compile($this->resolveRankings($catalog, $query, $user), $catalog, SecurityContext::forUser($user, $catalog));
    }

    /**
     * Replaces each top/bottom-N filter with the members it selects. The ranking
     * runs as its own governed query for the same user, with the query's other
     * filters and time range, so row-level security shapes the ranking too.
     */
    private function resolveRankings(Catalog $catalog, SemanticQuery $query, User $user, bool $useCache = true): SemanticQuery
    {
        $rankings = $query->rankingFilters();
        if ($rankings === []) {
            return $query;
        }
        $plain = array_values(array_filter($query->filters, fn ($f) => ! in_array($f, $rankings, true)));

        $resolved = $plain;
        foreach ($rankings as $f) {
            ['n' => $n, 'metric' => $metric] = $f['value'];
            $ranking = new SemanticQuery(
                metrics: [$metric],
                dimensions: [$f['dimension']],
                filters: $plain,
                timeRange: $query->timeRange,
                sort: [['key' => $metric, 'dir' => $f['op'] === 'top' ? 'desc' : 'asc']],
                limit: $n,
            );
            $members = $this->runOn($catalog, $ranking, $user, $useCache)->rows;
            $resolved[] = ['dimension' => $f['dimension'], 'op' => 'in', 'value' => array_column($members, $f['dimension'])];
        }

        return $query->withFilters($resolved);
    }

    private function compiler(): QueryCompiler
    {
        return new QueryCompiler($this->dialect, (int) config('aixbi.query.max_rows'));
    }
}
