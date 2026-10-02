<?php

namespace App\Http\Controllers\Api;

use App\Domain\Analytics\KpiService;
use App\Domain\Query\QueryService;
use App\Domain\Query\SemanticQuery;
use App\Domain\Semantic\CatalogRepository;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueryController extends Controller
{
    public function __construct(private readonly QueryService $queries, private readonly CatalogRepository $catalogs) {}

    public function run(Request $request): JsonResponse
    {
        $request->validate(['model' => 'required|string', 'metrics' => 'array', 'dimensions' => 'array', 'filters' => 'array', 'time' => 'array', 'sort' => 'array', 'limit' => 'integer|min:1']);
        $result = $this->queries->run($request->input('model'), SemanticQuery::fromArray($request->all()), $request->user(), ! $request->boolean('fresh'));
        $data = $result->toArray();
        if (! $request->user()->hasPermission('query.explain')) {
            unset($data['meta']['sql']); // executives see provenance, not SQL
        }

        return response()->json($data);
    }

    public function explain(Request $request): JsonResponse
    {
        $request->validate(['model' => 'required|string']);
        $catalog = $this->catalogs->get($request->input('model'));
        $compiled = $this->queries->explain($catalog, SemanticQuery::fromArray($request->all()), $request->user());

        return response()->json(['sql' => $compiled->sql, 'bindings' => $compiled->bindings, 'columns' => $compiled->columns, 'hash' => $compiled->hash()]);
    }

    public function kpis(Request $request, KpiService $kpis): JsonResponse
    {
        $data = $request->validate([
            'metrics' => 'required|array|min:1|max:20', 'metrics.*' => 'string', 'range' => 'required',
            'filters' => 'array', 'compare' => 'in:previous_period,previous_year',
        ]);

        return response()->json(['data' => $kpis->cards($data['metrics'], $data['range'], $request->user(), $data['filters'] ?? [], $data['compare'] ?? 'previous_period')]);
    }
}
