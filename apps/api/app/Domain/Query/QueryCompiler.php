<?php

namespace App\Domain\Query;

use App\Domain\Query\Dialect\Dialect;
use App\Domain\Semantic\Catalog;

/**
 * Compiles a SemanticQuery into parameterised SQL.
 *
 * Guarantees:
 *  - only identifiers declared in the semantic model reach SQL, always quoted;
 *  - every user-supplied value is a bound parameter;
 *  - row-level security predicates are always ANDed into WHERE;
 *  - sensitive dimensions require the data.sensitive permission;
 *  - output is a single read-only SELECT with a hard LIMIT.
 */
final class QueryCompiler
{
    /** @var array<string, string> dataset id => table alias */
    private array $aliases = [];

    /** @var array<int, string> */
    private array $joins = [];

    public function __construct(private readonly Dialect $dialect, private readonly int $maxRows) {}

    public function compile(SemanticQuery $query, Catalog $catalog, SecurityContext $security): CompiledQuery
    {
        $this->aliases = [$catalog->baseDatasetId => 't0'];
        $this->joins = [];
        $columns = [];
        $select = [];
        $selectBindings = [];
        $groupBy = [];

        // Time bucket first so results are naturally ordered series.
        if ($query->grain !== null) {
            $timeDim = $this->timeDimension($catalog);
            $expr = $this->dialect->timeBucket($query->grain, $this->dimensionColumn($catalog, $timeDim, $security));
            $select[] = $expr.' AS '.$this->dialect->quote('period');
            $groupBy[] = $expr;
            $columns[] = ['key' => 'period', 'label' => ucfirst($query->grain), 'role' => 'time', 'type' => 'date', 'grain' => $query->grain];
        }

        foreach ($query->dimensions as $key) {
            $dim = $catalog->dimension($key);
            $expr = $this->dimensionColumn($catalog, $dim, $security);
            $select[] = $expr.' AS '.$this->dialect->quote($key);
            $groupBy[] = $expr;
            $columns[] = ['key' => $key, 'label' => $dim['label'], 'role' => 'dimension', 'type' => $dim['type']];
        }

        $measureSql = function (string $measureKey) use ($catalog, &$selectBindings): string {
            [$sql, $bindings] = $this->measureSql($catalog, $catalog->measure($measureKey));
            array_push($selectBindings, ...$bindings);

            return $sql;
        };

        foreach ($query->metrics as $key) {
            $metric = $catalog->metric($key);
            $select[] = $catalog->metricAst($key)->toSql($measureSql).' AS '.$this->dialect->quote($key);
            $columns[] = ['key' => $key, 'label' => $metric['label'], 'role' => 'metric', 'type' => 'number', 'format' => $metric['format']];
        }
        foreach ($query->measures as $key) {
            if (in_array($key, $query->metrics, true)) {
                continue;
            }
            $measure = $catalog->measure($key);
            $select[] = $measureSql($key).' AS '.$this->dialect->quote('m__'.$key);
            $columns[] = ['key' => 'm__'.$key, 'label' => $measure['label'], 'role' => 'measure', 'type' => 'number', 'format' => 'number'];
        }

        [$where, $whereBindings] = $this->whereClause($query, $catalog, $security);

        $base = $catalog->baseDataset();
        $sql = 'SELECT '.implode(', ', $select)
            .' FROM '.$this->table($base).' t0'
            .($this->joins ? ' '.implode(' ', $this->joins) : '')
            .($where ? ' WHERE '.implode(' AND ', $where) : '')
            .($groupBy ? ' GROUP BY '.implode(', ', $groupBy) : '')
            .$this->orderBy($query, $columns);

        $limit = min($query->limit ?? $this->maxRows, $this->maxRows);
        // Fetch one extra row so the executor can report truncation honestly.
        $sql .= ' LIMIT '.($limit + 1);

        return new CompiledQuery($sql, [...$selectBindings, ...$whereBindings], $columns, $limit);
    }

    /** @return array{0: array<int, string>, 1: array<int, mixed>} */
    private function whereClause(SemanticQuery $query, Catalog $catalog, SecurityContext $security): array
    {
        $where = [];
        $bindings = [];

        if ($query->timeRange !== null) {
            $col = $this->dimensionColumn($catalog, $this->timeDimension($catalog), $security);
            $where[] = "{$col} >= ? AND {$col} < ?";
            $bindings[] = $query->timeRange->from->toDateString();
            $bindings[] = $query->timeRange->to->toDateString();
        }

        foreach ($query->filters as $filter) {
            $dim = $catalog->dimension($filter['dimension']);
            [$sql, $b] = $this->predicate($this->dimensionColumn($catalog, $dim, $security), $filter['op'], $filter['value'] ?? null);
            $where[] = $sql;
            array_push($bindings, ...$b);
        }

        // Row-level security is applied last and unconditionally.
        foreach ($security->rowFilters as $rls) {
            $dim = $catalog->dimension($rls['dimension']);
            if ($rls['values'] === []) {
                $where[] = '1 = 0';

                continue;
            }
            [$sql, $b] = $this->predicate($this->dimensionColumn($catalog, $dim, $security, enforceSensitivity: false), 'in', $rls['values']);
            $where[] = $sql;
            array_push($bindings, ...$b);
        }

        return [$where, $bindings];
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function predicate(string $col, string $op, mixed $value): array
    {
        $scalar = fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v;

        return match ($op) {
            'eq' => ["{$col} = ?", [$scalar($value)]],
            'neq' => ["{$col} <> ?", [$scalar($value)]],
            'gt' => ["{$col} > ?", [$scalar($value)]],
            'gte' => ["{$col} >= ?", [$scalar($value)]],
            'lt' => ["{$col} < ?", [$scalar($value)]],
            'lte' => ["{$col} <= ?", [$scalar($value)]],
            'in', 'not_in' => $this->inPredicate($col, $op, (array) $value),
            'between' => $this->between($col, $value),
            'contains' => [$this->dialect->caseInsensitiveContains($col), [$this->dialect->containsBinding((string) $value)]],
            'is_null' => ["{$col} IS NULL", []],
            'not_null' => ["{$col} IS NOT NULL", []],
            default => throw new QueryValidationException("Unsupported operator '{$op}'."),
        };
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function inPredicate(string $col, string $op, array $values): array
    {
        if ($values === []) {
            return [$op === 'in' ? '1 = 0' : '1 = 1', []];
        }
        if (count($values) > 1000) {
            throw new QueryValidationException('Filters are limited to 1,000 values.');
        }
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return ["{$col} ".($op === 'in' ? 'IN' : 'NOT IN')." ({$placeholders})", array_values($values)];
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function between(string $col, mixed $value): array
    {
        if (! is_array($value) || count($value) !== 2) {
            throw new QueryValidationException('between needs exactly two values.');
        }

        return ["{$col} BETWEEN ? AND ?", array_values($value)];
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function measureSql(Catalog $catalog, array $measure): array
    {
        $base = $catalog->baseDataset();
        $expr = null;
        if ($measure['field'] !== null) {
            $this->assertField($base, $measure['field']);
            $expr = 't0.'.$this->dialect->quote($measure['field']);
        }

        $filterSql = null;
        $bindings = [];
        if (! empty($measure['filters'])) {
            $parts = [];
            foreach ($measure['filters'] as $f) {
                $this->assertField($base, $f['field']);
                [$sql, $b] = $this->predicate('t0.'.$this->dialect->quote($f['field']), $f['op'], $f['value'] ?? null);
                $parts[] = $sql;
                array_push($bindings, ...$b);
            }
            $filterSql = implode(' AND ', $parts);
        }

        return [$this->dialect->aggregate($measure['aggregation'], $expr, $filterSql), $bindings];
    }

    private function dimensionColumn(Catalog $catalog, array $dim, SecurityContext $security, bool $enforceSensitivity = true): string
    {
        if ($enforceSensitivity && $dim['is_sensitive'] && ! $security->canSeeSensitive) {
            throw new QueryDeniedException("You are not authorised to use the sensitive field '{$dim['label']}'.");
        }
        $dataset = $catalog->datasets[$dim['dataset_id']] ?? throw new QueryValidationException("Dimension '{$dim['key']}' has no dataset.");
        $this->assertField($dataset, $dim['field']);

        return $this->aliasFor($catalog, $dim['dataset_id']).'.'.$this->dialect->quote($dim['field']);
    }

    private function timeDimension(Catalog $catalog): array
    {
        if ($catalog->timeDimension === null) {
            throw new QueryValidationException("Model '{$catalog->name}' has no time dimension.");
        }

        return $catalog->dimension($catalog->timeDimension);
    }

    /** Resolves (and registers) the join path from the base dataset via many-to-one relationships. */
    private function aliasFor(Catalog $catalog, string $datasetId): string
    {
        if (isset($this->aliases[$datasetId])) {
            return $this->aliases[$datasetId];
        }

        $path = $this->findPath($catalog, $catalog->baseDatasetId, $datasetId)
            ?? throw new QueryValidationException('No relationship connects '.$catalog->datasets[$datasetId]['label'].' to '.$catalog->baseDataset()['label'].'.');

        foreach ($path as $rel) {
            if (isset($this->aliases[$rel['to_dataset_id']])) {
                continue;
            }
            $from = $this->aliases[$rel['from_dataset_id']];
            $alias = 't'.count($this->aliases);
            $this->aliases[$rel['to_dataset_id']] = $alias;
            $target = $catalog->datasets[$rel['to_dataset_id']];
            $this->assertField($catalog->datasets[$rel['from_dataset_id']], $rel['from_field']);
            $this->assertField($target, $rel['to_field']);
            $this->joins[] = 'LEFT JOIN '.$this->table($target)." {$alias} ON {$from}.".$this->dialect->quote($rel['from_field'])
                ." = {$alias}.".$this->dialect->quote($rel['to_field']);
        }

        return $this->aliases[$datasetId];
    }

    /** @return array<int, array<string, string>>|null breadth-first shortest join path */
    private function findPath(Catalog $catalog, string $from, string $to): ?array
    {
        $queue = [[$from, []]];
        $seen = [$from => true];
        while ($queue) {
            [$node, $path] = array_shift($queue);
            foreach ($catalog->relationships as $rel) {
                if ($rel['from_dataset_id'] !== $node || isset($seen[$rel['to_dataset_id']])) {
                    continue;
                }
                $next = [...$path, $rel];
                if ($rel['to_dataset_id'] === $to) {
                    return $next;
                }
                $seen[$rel['to_dataset_id']] = true;
                $queue[] = [$rel['to_dataset_id'], $next];
            }
        }

        return null;
    }

    private function orderBy(SemanticQuery $query, array $columns): string
    {
        $keys = array_column($columns, 'key');
        $parts = [];
        $used = [];
        foreach ($query->sort as $s) {
            if (! in_array($s['key'], $keys, true)) {
                throw new QueryValidationException("Cannot sort by '{$s['key']}' — it is not in the query.");
            }
            $parts[] = $this->dialect->quote($s['key']).' '.strtoupper($s['dir'] ?? 'asc').' NULLS LAST';
            $used[$s['key']] = true;
        }
        // Grouping columns break ties so results are deterministic.
        foreach ($columns as $c) {
            if (in_array($c['role'], ['time', 'dimension'], true) && ! isset($used[$c['key']])) {
                $parts[] = $this->dialect->quote($c['key']).' ASC';
            }
        }

        return $parts ? ' ORDER BY '.implode(', ', $parts) : '';
    }

    private function table(array $dataset): string
    {
        return $this->dialect->quote($dataset['schema']).'.'.$this->dialect->quote($dataset['table']);
    }

    private function assertField(array $dataset, string $field): void
    {
        if (! array_key_exists($field, $dataset['fields'])) {
            throw new QueryValidationException("Field '{$field}' is not part of dataset '{$dataset['label']}'.");
        }
    }
}
