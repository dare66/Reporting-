<?php

namespace Tests\Unit;

use App\Domain\Query\Expression\ExpressionParser;
use App\Domain\Query\QueryValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExpressionParserTest extends TestCase
{
    private ExpressionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ExpressionParser(['approved', 'decided', 'met', 'measured']);
    }

    public function test_parses_ratio_and_evaluates(): void
    {
        $ast = $this->parser->parse('approved / decided');
        $this->assertSame(['approved', 'decided'], $ast->references());
        $this->assertEqualsWithDelta(0.75, $ast->evaluate(['approved' => 75, 'decided' => 100]), 1e-12);
    }

    public function test_respects_precedence_and_parentheses(): void
    {
        $this->assertEqualsWithDelta(7.0, $this->parser->parse('1 + 2 * 3')->evaluate([]), 1e-12);
        $this->assertEqualsWithDelta(9.0, $this->parser->parse('(1 + 2) * 3')->evaluate([]), 1e-12);
        $this->assertEqualsWithDelta(5.0, $this->parser->parse('-(measured - met)')->evaluate(['measured' => 10, 'met' => 15]), 1e-12);
    }

    public function test_division_by_zero_is_null_not_error(): void
    {
        $this->assertNull($this->parser->parse('approved / decided')->evaluate(['approved' => 1, 'decided' => 0]));
    }

    public function test_compiles_division_to_safe_sql(): void
    {
        $sql = $this->parser->parse('approved / decided')->toSql(fn ($k) => "M({$k})");
        $this->assertSame('(CAST(M(approved) AS DOUBLE PRECISION) / NULLIF(M(decided), 0))', $sql);
    }

    #[DataProvider('maliciousExpressions')]
    public function test_rejects_anything_but_known_measures_and_arithmetic(string $expr): void
    {
        $this->expectException(QueryValidationException::class);
        $this->parser->parse($expr);
    }

    public static function maliciousExpressions(): array
    {
        return [
            'sql injection' => ['approved; DROP TABLE users'],
            'unknown identifier' => ['revenue / decided'],
            'function call' => ['pg_sleep(10)'],
            'quote' => ["approved + '1'"],
            'unbalanced' => ['(approved / decided'],
            'dangling operator' => ['approved /'],
            'comment' => ['approved -- x'],
        ];
    }
}
