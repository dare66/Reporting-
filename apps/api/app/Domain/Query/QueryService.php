<?php

namespace App\Domain\Query;

use App\Domain\Query\Dialect\Dialect;
use App\Domain\Semantic\Catalog;
use App\Domain\Semantic\CatalogRepository;
use App\Models\User;

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

        return $this->executor->execute($compiled, $security, ['model' => $catalog->key] + $query->toArray(), $useCache);
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
