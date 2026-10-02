<?php

namespace App\Domain\Analytics;

/** Executive number formatting shared by narratives, notifications and exports. */
final class Format
{
    public static function value(?float $v, string $format, string $currency = 'RM'): string
    {
        if ($v === null) {
            return '—';
        }

        return match ($format) {
            'percent' => number_format($v * 100, 1).'%',
            'currency' => $currency.' '.self::compact($v),
            'duration_days' => number_format($v, 1).' days',
            default => self::compact($v),
        };
    }

    public static function compact(float $v): string
    {
        $abs = abs($v);

        return match (true) {
            $abs >= 1e9 => number_format($v / 1e9, 2).'B',
            $abs >= 1e6 => number_format($v / 1e6, 2).'M',
            $abs >= 1e4 => number_format($v / 1e3, 1).'K',
            $abs >= 100 => number_format($v, 0),
            default => rtrim(rtrim(number_format($v, 2), '0'), '.'),
        };
    }

    /** Change wording: percentage points for rates, relative % otherwise. */
    public static function change(?float $change, ?float $changePct, string $format): string
    {
        if ($change === null) {
            return 'no comparison available';
        }
        $sign = $change >= 0 ? '+' : '−';
        if ($format === 'percent') {
            return $sign.number_format(abs($change) * 100, 1).' pts';
        }
        if ($changePct !== null) {
            return $sign.number_format(abs($changePct) * 100, 1).'%';
        }

        return $sign.self::compact(abs($change));
    }

    /** "Last 30 days" → "the last 30 days"; calendar labels pass through. */
    public static function period(string $label): string
    {
        return str_starts_with($label, 'Last ') || str_starts_with($label, 'This ') ? 'the '.lcfirst($label) : $label;
    }
}
