<?php

namespace App\Domain\Query\Expression;

use App\Domain\Query\QueryValidationException;

/**
 * Recursive-descent parser for metric expressions:
 *
 *   expr   := term (('+' | '-') term)*
 *   term   := factor (('*' | '/') factor)*
 *   factor := NUMBER | IDENT | '(' expr ')' | '-' factor
 *
 * IDENT must be a known measure key, which is what keeps metric SQL injection-proof.
 */
final class ExpressionParser
{
    /** @var array<int, array{0: string, 1: string}> */
    private array $tokens = [];

    private int $pos = 0;

    /** @param array<string> $knownMeasures */
    public function __construct(private readonly array $knownMeasures) {}

    public function parse(string $expression): Node
    {
        $this->tokens = $this->tokenize($expression);
        $this->pos = 0;
        $node = $this->expr();
        if ($this->pos < count($this->tokens)) {
            throw new QueryValidationException("Unexpected '{$this->tokens[$this->pos][1]}' in expression.");
        }

        return $node;
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function tokenize(string $s): array
    {
        if (! preg_match_all('/\s*(?:(\d+(?:\.\d+)?)|([a-z_][a-z0-9_]*)|([-+*\/()]))\s*/A', $s, $m, PREG_SET_ORDER)
            || strlen(implode('', array_column($m, 0))) !== strlen($s)) {
            throw new QueryValidationException('Metric expression contains invalid characters.');
        }

        return array_map(fn ($t) => match (true) {
            ($t[1] ?? '') !== '' => ['num', $t[1]],
            ($t[2] ?? '') !== '' => ['ident', $t[2]],
            default => ['op', $t[3]],
        }, $m);
    }

    private function expr(): Node
    {
        $node = $this->term();
        while ($this->peekOp('+', '-')) {
            $op = $this->tokens[$this->pos++][1];
            $node = new BinaryNode($op, $node, $this->term());
        }

        return $node;
    }

    private function term(): Node
    {
        $node = $this->factor();
        while ($this->peekOp('*', '/')) {
            $op = $this->tokens[$this->pos++][1];
            $node = new BinaryNode($op, $node, $this->factor());
        }

        return $node;
    }

    private function factor(): Node
    {
        $tok = $this->tokens[$this->pos] ?? null;
        if ($tok === null) {
            throw new QueryValidationException('Metric expression ended unexpectedly.');
        }
        $this->pos++;

        return match (true) {
            $tok[0] === 'num' => new NumberNode((float) $tok[1]),
            $tok[0] === 'ident' => $this->measure($tok[1]),
            $tok === ['op', '('] => $this->group(),
            $tok === ['op', '-'] => new NegateNode($this->factor()),
            default => throw new QueryValidationException("Unexpected '{$tok[1]}' in expression."),
        };
    }

    private function group(): Node
    {
        $node = $this->expr();
        if (! $this->peekOp(')')) {
            throw new QueryValidationException('Unbalanced parentheses in expression.');
        }
        $this->pos++;

        return $node;
    }

    private function measure(string $key): Node
    {
        if (! in_array($key, $this->knownMeasures, true)) {
            throw new QueryValidationException("Unknown measure '{$key}' in expression.");
        }

        return new MeasureNode($key);
    }

    private function peekOp(string ...$ops): bool
    {
        $tok = $this->tokens[$this->pos] ?? null;

        return $tok !== null && $tok[0] === 'op' && in_array($tok[1], $ops, true);
    }
}
