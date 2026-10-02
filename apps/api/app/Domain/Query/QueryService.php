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
        $compiled = $this->compiler()->compile($query, $catalog, $security);

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

    public function explain(Catalog $catalog, SemanticQuery $query, User $user): CompiledQuery
    {
        return $this->compiler()->compile($query, $catalog, SecurityContext::forUser($user, $catalog));
    }

    private function compiler(): QueryCompiler
    {
        return new QueryCompiler($this->dialect, (int) config('aixbi.query.max_rows'));
    }
}
