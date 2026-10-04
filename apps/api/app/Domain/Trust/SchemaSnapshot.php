<?php

namespace App\Domain\Trust;

/**
 * The shape of a dataset at one moment, and what changed between two moments.
 * Pure: no database access, so the rules are easy to test and explain.
 *
 * @phpstan-type Column array{type: string, null_pct: float, distinct: int, min: string|null, max: string|null, values: list<string>|null}
 * @phpstan-type Change array{kind: string, column: string|null, severity: string, message: string, before: array<string, mixed>|null, after: array<string, mixed>|null}
 */
class SchemaSnapshot
{
    /** Values are listed only for columns this small, so "new value" means genuinely new, not a reshuffled top 8. */
    private const COMPLETE_VALUES = 8;

    /** Null share must grow by at least this many percentage points to count. */
    private const NULL_JUMP = 10.0;

    /** Row count must fall below this share of the previous load to count. */
    private const ROW_DROP = 0.7;

    /**
     * @param  iterable<array{name: string, data_type: string, profile: array<string, mixed>}>  $fields
     * @return array<string, Column>
     */
    public function columns(iterable $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $p = $f['profile'];
            $distinct = (int) ($p['distinct'] ?? 0);
            $values = isset($p['top_values']) && $distinct <= self::COMPLETE_VALUES ? array_map(fn ($v) => (string) $v['value'], $p['top_values']) : null;
            $out[$f['name']] = ['type' => $f['data_type'], 'null_pct' => (float) ($p['null_pct'] ?? 0), 'distinct' => $distinct,
                'min' => isset($p['min']) ? (string) $p['min'] : null, 'max' => isset($p['max']) ? (string) $p['max'] : null, 'values' => $values];
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, Column>  $before
     * @param  array<string, Column>  $after
     * @return list<Change>
     */
    public function diff(array $before, array $after, int $rowsBefore, int $rowsAfter): array
    {
        $changes = [];
        $removed = array_diff_key($before, $after);
        $added = array_diff_key($after, $before);

        // A column that vanished while a near-identical one appeared was most likely renamed.
        foreach ($removed as $old => $o) {
            foreach ($added as $new => $n) {
                if ($o['type'] === $n['type'] && abs($o['null_pct'] - $n['null_pct']) < 5 && $this->similar($o['distinct'], $n['distinct'])) {
                    $changes[] = $this->change('column_renamed', $new, 'warning', "{$old} appears to have been renamed to {$new}.", ['name' => $old] + $o, ['name' => $new] + $n);
                    unset($removed[$old], $added[$new]);

                    break;
                }
            }
        }
        foreach ($removed as $name => $o) {
            $changes[] = $this->change('column_removed', $name, 'critical', "{$name} is no longer in the data.", $o, null);
        }
        foreach ($added as $name => $n) {
            $changes[] = $this->change('column_added', $name, 'info', "New column {$name} ({$n['type']}).", null, $n);
        }

        foreach (array_intersect_key($after, $before) as $name => $n) {
            $o = $before[$name];
            if ($o['type'] !== $n['type']) {
                $changes[] = $this->change('type_changed', $name, 'critical', "{$name} changed from {$o['type']} to {$n['type']}.", $o, $n);

                continue;
            }
            if ($n['null_pct'] - $o['null_pct'] >= self::NULL_JUMP) {
                $changes[] = $this->change('nulls_increased', $name, 'warning',
                    sprintf('%s is now %.1f%% empty (was %.1f%%).', $name, $n['null_pct'], $o['null_pct']), $o, $n);
            }
            if (in_array($n['type'], ['integer', 'decimal'], true) && ($shift = $this->rangeShift($o, $n))) {
                $changes[] = $this->change('range_shifted', $name, 'warning', "{$name} {$shift}", $o, $n);
            }
            if ($o['values'] !== null && $n['values'] !== null && ($new = array_values(array_diff($n['values'], $o['values'])))) {
                $changes[] = $this->change('new_values', $name, 'info', "{$name} has new values: ".implode(', ', $new).'.', $o, $n);
            }
        }

        if ($rowsBefore > 0 && $rowsAfter < $rowsBefore * self::ROW_DROP) {
            $changes[] = $this->change('row_count_dropped', null, 'warning',
                sprintf('Row count fell from %s to %s (%.0f%% fewer).', number_format($rowsBefore), number_format($rowsAfter), (1 - $rowsAfter / $rowsBefore) * 100),
                ['rows' => $rowsBefore], ['rows' => $rowsAfter]);
        }

        return $changes;
    }

    /**
     * Values far outside the previous range: beyond it by more than its own width.
     *
     * @param  Column  $o
     * @param  Column  $n
     */
    private function rangeShift(array $o, array $n): ?string
    {
        if (! is_numeric($o['min']) || ! is_numeric($o['max']) || ! is_numeric($n['min']) || ! is_numeric($n['max'])) {
            return null;
        }
        $span = (float) $o['max'] - (float) $o['min'];
        if ($span <= 0) {
            return null;
        }

        return match (true) {
            (float) $n['max'] > (float) $o['max'] + $span => "now reaches {$n['max']}, far above its previous range ({$o['min']} – {$o['max']}).",
            (float) $n['min'] < (float) $o['min'] - $span => "now goes down to {$n['min']}, far below its previous range ({$o['min']} – {$o['max']}).",
            default => null,
        };
    }

    private function similar(int $a, int $b): bool
    {
        return max($a, $b) === 0 || abs($a - $b) / max($a, $b) <= 0.1;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return Change
     */
    private function change(string $kind, ?string $column, string $severity, string $message, ?array $before, ?array $after): array
    {
        return ['kind' => $kind, 'column' => $column, 'severity' => $severity, 'message' => $message, 'before' => $before, 'after' => $after];
    }
}
