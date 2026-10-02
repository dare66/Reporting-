<?php

namespace Tests\Unit;

use App\Domain\Query\Dialect\ClickHouseDialect;
use App\Domain\Query\Dialect\PostgresDialect;
use App\Domain\Query\QueryCompiler;
use App\Domain\Query\QueryDeniedException;
use App\Domain\Query\QueryValidationException;
use App\Domain\Query\SecurityContext;
use App\Domain\Query\SemanticQuery;
use App\Domain\Query\TimeRange;
use App\Domain\Semantic\Catalog;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class QueryCompilerTest extends TestCase
{
    private function catalog(): Catalog
    {
        $apps = ['id' => 'ds-app', 'name' => 'applications', 'label' => 'Applications', 'schema' => 'analytics', 'table' => 'applications',
            'fields' => ['id' => 'integer', 'country_id' => 'integer', 'status' => 'string', 'submitted_on' => 'date', 'processing_days' => 'integer', 'ref' => 'string']];
        $countries = ['id' => 'ds-c', 'name' => 'countries', 'label' => 'Countries', 'schema' => 'analytics', 'table' => 'countries',
            'fields' => ['id' => 'integer', 'code' => 'string', 'name' => 'string']];
        $dim = fn ($k, $ds, $f, $type = 'string', $sens = false) => ['key' => $k, 'label' => ucfirst($k), 'dataset_id' => $ds, 'field' => $f, 'type' => $type,
            'is_sensitive' => $sens, 'synonyms' => [], 'description' => null, 'root_cause_candidate' => true];

        return new Catalog('m1', 'applications', 'Applications', 'ds-app', 'submitted_on',
            ['ds-app' => $apps, 'ds-c' => $countries],
            ['submitted_on' => $dim('submitted_on', 'ds-app', 'submitted_on', 'time'), 'country' => $dim('country', 'ds-c', 'name'),
                'country_code' => $dim('country_code', 'ds-c', 'code'), 'status' => $dim('status', 'ds-app', 'status'), 'ref' => $dim('ref', 'ds-app', 'ref', 'string', true)],
            ['n' => ['key' => 'n', 'label' => 'N', 'aggregation' => 'count', 'field' => null, 'filters' => []],
                'approved' => ['key' => 'approved', 'label' => 'Approved', 'aggregation' => 'count', 'field' => null, 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'approved']]],
                'days' => ['key' => 'days', 'label' => 'Days', 'aggregation' => 'avg', 'field' => 'processing_days', 'filters' => []]],
            ['total' => ['key' => 'total', 'label' => 'Total', 'expression' => 'n', 'format' => 'number', 'higher_is_better' => true, 'target' => null, 'synonyms' => [], 'is_kpi' => true, 'description' => null, 'owner' => null],
                'approval_rate' => ['key' => 'approval_rate', 'label' => 'Approval', 'expression' => 'approved / n', 'format' => 'percent', 'higher_is_better' => true, 'target' => null, 'synonyms' => [], 'is_kpi' => true, 'description' => null, 'owner' => null]],
            [['from_dataset_id' => 'ds-app', 'from_field' => 'country_id', 'to_dataset_id' => 'ds-c', 'to_field' => 'id']],
        );
    }

    private function compile(SemanticQuery $q, ?SecurityContext $sec = null, $dialect = null)
    {
        return (new QueryCompiler($dialect ?? new PostgresDialect, 1000))->compile($q, $this->catalog(), $sec ?? new SecurityContext('org', 'u', false));
    }

    public function test_joins_dimension_tables_and_binds_every_value(): void
    {
        $c = $this->compile(new SemanticQuery(metrics: ['approval_rate'], dimensions: ['country'], filters: [['dimension' => 'status', 'op' => 'in', 'value' => ["x'); DROP TABLE users; --", 'b']]]));
        $this->assertStringContainsString('LEFT JOIN "analytics"."countries" t1 ON t0."country_id" = t1."id"', $c->sql);
        $this->assertStringContainsString('t0."status" IN (?, ?)', $c->sql);
        $this->assertStringNotContainsString('DROP', $c->sql);
        $this->assertSame(['approved', "x'); DROP TABLE users; --", 'b'], $c->bindings);
        $this->assertStringEndsWith('LIMIT 1001', $c->sql); // one extra row to detect truncation
    }

    public function test_time_bucket_and_range(): void
    {
        $range = TimeRange::resolve('last_month', CarbonImmutable::parse('2026-10-02'));
        $c = $this->compile(new SemanticQuery(metrics: ['total'], grain: 'month', timeRange: $range));
        $this->assertStringContainsString("CAST(date_trunc('month', t0.\"submitted_on\") AS DATE) AS \"period\"", $c->sql);
        $this->assertSame(['2026-09-01', '2026-10-01'], $c->bindings);
        $this->assertSame('period', $c->columns[0]['key']);
    }

    public function test_row_level_security_is_always_applied(): void
    {
        $sec = new SecurityContext('org', 'u', false, [['dimension' => 'country_code', 'values' => ['CN', 'IN']]]);
        $c = $this->compile(new SemanticQuery(metrics: ['total']), $sec);
        $this->assertStringContainsString('t1."code" IN (?, ?)', $c->sql);
        $this->assertSame(['CN', 'IN'], $c->bindings);
    }

    public function test_row_level_security_with_no_values_returns_nothing(): void
    {
        $sec = new SecurityContext('org', 'u', false, [['dimension' => 'country_code', 'values' => []]]);
        $this->assertStringContainsString('WHERE 1 = 0', $this->compile(new SemanticQuery(metrics: ['total']), $sec)->sql);
    }

    public function test_sensitive_dimension_requires_permission(): void
    {
        $this->expectException(QueryDeniedException::class);
        $this->compile(new SemanticQuery(metrics: ['total'], dimensions: ['ref']));
    }

    public function test_sensitive_dimension_allowed_with_permission(): void
    {
        $c = $this->compile(new SemanticQuery(metrics: ['total'], dimensions: ['ref']), new SecurityContext('org', 'u', true));
        $this->assertStringContainsString('t0."ref" AS "ref"', $c->sql);
    }

    public function test_rejects_unknown_dimension_and_unsortable_keys(): void
    {
        try {
            $this->compile(new SemanticQuery(metrics: ['total'], dimensions: ['password']));
            $this->fail('expected exception');
        } catch (QueryValidationException) {
        }
        $this->expectException(QueryValidationException::class);
        $this->compile(new SemanticQuery(metrics: ['total'], sort: [['key' => 'status', 'dir' => 'asc']]));
    }

    public function test_limit_is_capped_by_max_rows(): void
    {
        $this->assertStringEndsWith('LIMIT 1001', $this->compile(new SemanticQuery(metrics: ['total'], dimensions: ['country'], limit: 1_000_000))->sql);
    }

    public function test_clickhouse_dialect_uses_if_combinators(): void
    {
        $c = $this->compile(new SemanticQuery(metrics: ['approval_rate'], grain: 'month', timeRange: TimeRange::resolve('last_month')), null, new ClickHouseDialect);
        $this->assertStringContainsString('countIf(t0.`status` = ?)', $c->sql);
        $this->assertStringContainsString('toStartOfMonth(t0.`submitted_on`)', $c->sql);
    }

    public function test_query_requires_a_metric(): void
    {
        $this->expectException(QueryValidationException::class);
        new SemanticQuery(metrics: []);
    }
}
