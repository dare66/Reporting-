<?php

namespace App\Domain\Reports\Export;

use App\Domain\Analytics\Format;

/** Minimal, dependency-free SVG charts for print renderers (PDF). */
final class SvgChart
{
    public static function line(array $series, string $format, array $theme, int $w = 680, int $h = 220, bool $area = true, ?array $band = null): string
    {
        $vals = array_map(fn ($p) => $p['value'], $series);
        $all = array_filter([...$vals, ...array_column($band ?? [], 'lower'), ...array_column($band ?? [], 'upper')], fn ($v) => $v !== null);
        if (count($all) < 2) {
            return '';
        }
        [$pl, $pr, $pt, $pb] = [56, 12, 12, 28];
        $min = min($all);
        $max = max($all);
        $pad = ($max - $min) * 0.08 ?: abs($max) * 0.1 + 1;
        $min = $format === 'percent' ? max(0, $min - $pad) : max(0, $min - $pad);
        $max += $pad;
        $n = count($series) + count($band ?? []);
        $x = fn ($i) => $pl + ($w - $pl - $pr) * ($n > 1 ? $i / ($n - 1) : 0);
        $y = fn ($v) => $pt + ($h - $pt - $pb) * (1 - ($v - $min) / (($max - $min) ?: 1));
        $c = '#'.$theme['series'][0];

        $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='{$w}' height='{$h}' viewBox='0 0 {$w} {$h}' font-family='DejaVu Sans' font-size='10'>";
        for ($g = 0; $g <= 4; $g++) {
            $v = $min + ($max - $min) * $g / 4;
            $gy = round($y($v), 1);
            $svg .= "<line x1='{$pl}' y1='{$gy}' x2='".($w - $pr)."' y2='{$gy}' stroke='#E4E6EB' stroke-width='1'/>";
            $svg .= "<text x='".($pl - 6)."' y='".($gy + 3)."' text-anchor='end' fill='#{$theme['muted']}'>".htmlspecialchars(Format::value($v, $format))."</text>";
        }
        $pts = [];
        foreach ($series as $i => $p) {
            if ($p['value'] !== null) {
                $pts[] = round($x($i), 1).','.round($y($p['value']), 1);
            }
        }
        if ($area && $pts) {
            $svg .= "<polygon points='".round($x(0), 1).','.($h - $pb).' '.implode(' ', $pts).' '.explode(',', end($pts))[0].','.($h - $pb)."' fill='{$c}' fill-opacity='0.12'/>";
        }
        $svg .= "<polyline points='".implode(' ', $pts)."' fill='none' stroke='{$c}' stroke-width='2.2' stroke-linejoin='round'/>";

        if ($band) {
            $offset = count($series) - 1;
            $upper = [];
            $lower = [];
            $mid = [round($x($offset), 1).','.round($y(end($vals)), 1)];
            foreach ($band as $j => $p) {
                $upper[] = round($x($offset + $j + 1), 1).','.round($y($p['upper']), 1);
                $lower[] = round($x($offset + $j + 1), 1).','.round($y($p['lower']), 1);
                $mid[] = round($x($offset + $j + 1), 1).','.round($y($p['value']), 1);
            }
            $ac = '#'.$theme['accent'];
            $svg .= "<polygon points='".implode(' ', [...$upper, ...array_reverse($lower)])."' fill='{$ac}' fill-opacity='0.18'/>";
            $svg .= "<polyline points='".implode(' ', $mid)."' fill='none' stroke='{$ac}' stroke-width='2.2' stroke-dasharray='5 4'/>";
        }

        $labels = [...array_column($series, 'period'), ...array_column($band ?? [], 'period')];
        $step = max(1, (int) ceil(count($labels) / 8));
        foreach ($labels as $i => $l) {
            if ($i % $step === 0) {
                $svg .= "<text x='".round($x($i), 1)."' y='".($h - 8)."' text-anchor='middle' fill='#{$theme['muted']}'>".date(strlen((string) $l) >= 10 ? 'M y' : 'M', strtotime((string) $l)).'</text>';
            }
        }

        return $svg.'</svg>';
    }

    public static function bars(array $rows, string $format, array $theme, int $w = 680, int $rowH = 22): string
    {
        if (! $rows) {
            return '';
        }
        $h = count($rows) * $rowH + 8;
        $max = max(array_map(fn ($r) => abs($r['value'] ?? 0), $rows)) ?: 1;
        $labelW = 190;
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='{$w}' height='{$h}' viewBox='0 0 {$w} {$h}' font-family='DejaVu Sans' font-size='10'>";
        foreach ($rows as $i => $r) {
            $y = 4 + $i * $rowH;
            $bw = round(($w - $labelW - 90) * abs($r['value'] ?? 0) / $max, 1);
            $color = $i === 0 ? '#'.$theme['accent'] : '#'.$theme['series'][0];
            $svg .= "<text x='".($labelW - 8)."' y='".($y + 14)."' text-anchor='end' fill='#{$theme['text']}'>".htmlspecialchars(mb_strimwidth((string) $r['member'], 0, 32, '…')).'</text>';
            $svg .= "<rect x='{$labelW}' y='".($y + 4)."' width='{$bw}' height='".($rowH - 9)."' rx='3' fill='{$color}'/>";
            $svg .= "<text x='".($labelW + $bw + 6)."' y='".($y + 14)."' fill='#{$theme['muted']}'>".htmlspecialchars(Format::value($r['value'], $format)).'</text>';
        }

        return $svg.'</svg>';
    }

    public static function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
