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
                'approved_total' => ['key' => 'approved_total', 'label' => 'Approved', 'expression' => 'approved', 'format' => 'number', 'higher_is_better' => true, 'target' => null, 'synonyms' => [], 'is_kpi' => false, 'description' => null, 'owner' => null],
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

    // ── Widget Studio: filters, measure filters and quick functions ────────────

    public function test_text_filters_escape_wildcards_and_treat_missing_text_as_not_containing(): void
    {
        $c = $this->compile(new SemanticQuery(metrics: ['total'], filters: [
            ['dimension' => 'status', 'op' => 'starts_with', 'value' => '50%_off'],
            ['dimension' => 'country', 'op' => 'ends_with', 'value' => 'land'],
            ['dimension' => 'country', 'op' => 'not_contains', 'value' => 'ia'],
        ]));
        $this->assertStringContainsString('t0."status" ILIKE ?', $c->sql);
        $this->assertStringContainsString('(t1."name" IS NULL OR NOT t1."name" ILIKE ?)', $c->sql);
        $this->assertSame(['50\%\_off%', '%land', '%ia%'], $c->bindings);
    }

    public function test_text_filters_need_text(): void
    {
        $this->expectException(QueryValidationException::class);
        $this->compile(new SemanticQuery(metrics: ['total'], filters: [['dimension' => 'status', 'op' => 'contains', 'value' => '']]));
    }

    public function test_not_between_and_clickhouse_text_match(): void
    {
        $q = new SemanticQuery(metrics: ['total'], filters: [
            ['dimension' => 'status', 'op' => 'not_between', 'value' => ['a', 'm']],
            ['dimension' => 'status', 'op' => 'contains', 'value' => 'pend'],
        ]);
        $this->assertStringContainsString('t0."status" NOT BETWEEN ? AND ?', $this->compile($q)->sql);
        $this->assertStringContainsString('ilike(t0.`status`, ?)', $this->compile($q, null, new ClickHouseDialect)->sql);
    }

    public function test_measure_filters_compile_to_having_with_bindings_in_sql_order(): void
    {
        $c = $this->compile(new SemanticQuery(
            metrics: ['approved_total'],
            dimensions: ['country'],
            filters: [['dimension' => 'status', 'op' => 'neq', 'value' => 'draft']],
            having: [['metric' => 'approval_rate', 'op' => 'lt', 'value' => 0.5]],
        ));
        $this->assertStringContainsString('GROUP BY t1."name" HAVING (CAST(COALESCE(COUNT(*) FILTER (WHERE t0."status" = ?), 0) AS DOUBLE PRECISION) / NULLIF(COALESCE(COUNT(*), 0), 0)) < ? ORDER BY', $c->sql);
        // select (approved), where (draft), having (approved, 0.5)
        $this->assertSame(['approved', 'draft', 'approved', 0.5], $c->bindings);
    }

    public function test_share_of_total_is_a_window_over_the_whole_result(): void
    {
        $c = $this->compile(new SemanticQuery(metrics: ['total'], dimensions: ['country'], calculations: [['fn' => 'percent_of_total', 'metric' => 'total']], limit: 5));
        $this->assertStringContainsString('COALESCE(COUNT(*), 0) * 1.0 / NULLIF(SUM(COALESCE(COUNT(*), 0)) OVER (), 0) AS "total__percent_of_total"', $c->sql);
        $column = $c->columns[2];
        $this->assertSame(['total__percent_of_total', 'Total · % of total', 'percent'], [$column['key'], $column['label'], $column['format']]);
    }

    public function test_time_calculations_partition_series_by_break_by_dimension(): void
    {
        $c = $this->compile(new SemanticQuery(metrics: ['approved_total'], dimensions: ['country'], grain: 'month', calculations: [
            ['fn' => 'running_sum', 'metric' => 'approved_total'],
            ['fn' => 'moving_average', 'metric' => 'approved_total', 'window' => 3],
            ['fn' => 'year_to_date', 'metric' => 'approved_total'],
        ]));
        $period = "CAST(date_trunc('month', t0.\"submitted_on\") AS DATE)";
        $this->assertStringContainsString("OVER (PARTITION BY t1.\"name\" ORDER BY {$period}) AS \"approved_total__running_sum\"", $c->sql);
        $this->assertStringContainsString('ROWS BETWEEN 2 PRECEDING AND CURRENT ROW) AS "approved_total__moving_average"', $c->sql);
        $this->assertStringContainsString("PARTITION BY t1.\"name\", CAST(date_trunc('year', {$period}) AS DATE) ORDER BY", $c->sql);
        // Each repetition of the filtered measure carries its own binding, in SQL order.
        $this->assertSame(['approved', 'approved', 'approved', 'approved'], $c->bindings);
    }

    public function test_period_over_period_change_is_null_safe(): void
    {
        $c = $this->compile(new SemanticQuery(metrics: ['total'], grain: 'month', calculations: [['fn' => 'percent_change', 'metric' => 'total'], ['fn' => 'difference', 'metric' => 'total']]));
        $this->assertStringContainsString('NULLIF(ABS(LAG(COALESCE(COUNT(*), 0)) OVER (ORDER BY', $c->sql);
        $this->assertSame('percent', $c->columns[2]['format']);
        $this->assertSame('number', $c->columns[3]['format']);
    }

    public function test_rank_is_per_period_when_there_is_a_time_axis(): void
    {
        $c = $this->compile(new SemanticQuery(metrics: ['total'], dimensions: ['country'], grain: 'month', calculations: [['fn' => 'rank', 'metric' => 'total']]));
        $this->assertStringContainsString("RANK() OVER (PARTITION BY CAST(date_trunc('month', t0.\"submitted_on\") AS DATE) ORDER BY COALESCE(COUNT(*), 0) DESC)", $c->sql);
    }

    /** @return iterable<string, array{0: SemanticQuery, 1: string}> */
    public static function inapplicableCalculations(): iterable
    {
        yield 'running total without time' => [new SemanticQuery(metrics: ['total'], dimensions: ['country'], calculations: [['fn' => 'running_sum', 'metric' => 'total']]), 'needs a time axis'];
        yield 'share of a ratio' => [new SemanticQuery(metrics: ['approval_rate'], dimensions: ['country'], calculations: [['fn' => 'percent_of_total', 'metric' => 'approval_rate']]), 'cannot be added up'];
        yield 'year to date by year' => [new SemanticQuery(metrics: ['total'], grain: 'year', calculations: [['fn' => 'year_to_date', 'metric' => 'total']]), 'finer than a year'];
        yield 'rank without a dimension' => [new SemanticQuery(metrics: ['total'], grain: 'month', calculations: [['fn' => 'rank', 'metric' => 'total']]), 'needs a dimension'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inapplicableCalculations')]
    public function test_rejects_calculations_that_would_return_wrong_numbers(SemanticQuery $query, string $reason): void
    {
        $this->expectException(QueryValidationException::class);
        $this->expectExceptionMessage($reason);
        $this->compile($query);
    }

    public function test_additivity_follows_the_metric_expression(): void
    {
        $catalog = $this->catalog();
        $this->assertTrue($catalog->isAdditive('total'));
        $this->assertTrue($catalog->isAdditive('approved_total'));
        $this->assertFalse($catalog->isAdditive('approval_rate'));
    }

    public function test_ranking_filters_must_be_resolved_before_compiling(): void
    {
        $this->expectException(QueryValidationException::class);
        $this->compile(SemanticQuery::fromArray(['metrics' => ['total'], 'filters' => [['dimension' => 'country', 'op' => 'top', 'value' => ['n' => 5, 'metric' => 'total']]]]));
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalidStudioInput(): iterable
    {
        yield 'quick function on an unselected metric' => [['metrics' => ['total'], 'calculations' => [['fn' => 'rank', 'metric' => 'approval_rate']]]];
        yield 'unknown quick function' => [['metrics' => ['total'], 'calculations' => [['fn' => 'median', 'metric' => 'total']]]];
        yield 'moving average window too wide' => [['metrics' => ['total'], 'calculations' => [['fn' => 'moving_average', 'metric' => 'total', 'window' => 99]]]];
        yield 'ranking without a count' => [['metrics' => ['total'], 'filters' => [['dimension' => 'country', 'op' => 'top', 'value' => ['metric' => 'total']]]]];
        yield 'having with a text operator' => [['metrics' => ['total'], 'having' => [['metric' => 'total', 'op' => 'contains', 'value' => 'x']]]];
    }

    /** @param array<string, mixed> $input */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidStudioInput')]
    public function test_rejects_malformed_studio_input_at_the_boundary(array $input): void
    {
        $this->expectException(QueryValidationException::class);
        SemanticQuery::fromArray($input);
    }
}
