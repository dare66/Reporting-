<?php

namespace App\Domain\Dashboards;

/**
 * Derives tablet (8-col) and mobile (4-col) layouts from the desktop 12-col
 * layout. Widgets are re-flowed — never shrunk: order follows widget priority
 * (what matters most comes first), KPIs pair up, everything else goes full width.
 */
class LayoutReflow
{
    public const COLUMNS = ['desktop' => 12, 'tablet' => 8, 'mobile' => 4];

    /**
     * @param  array<int, array{id: string, type: string, section: ?string, priority: int, position: array{x:int,y:int,w:int,h:int}}>  $widgets
     * @return array<string, array<int, array{id: string, x: int, y: int, w: int, h: int, section: ?string}>>
     */
    public function layouts(array $widgets): array
    {
        return [
            'desktop' => array_map(fn ($w) => ['id' => $w['id'], 'section' => $w['section']] + $w['position'], $widgets),
            'tablet' => $this->pack($widgets, 8),
            'mobile' => $this->pack($widgets, 4),
        ];
    }

    private function pack(array $widgets, int $cols): array
    {
        usort($widgets, fn ($a, $b) => [$a['priority'], $a['position']['y'], $a['position']['x']] <=> [$b['priority'], $b['position']['y'], $b['position']['x']]);

        // KPIs lead on small screens regardless of their desktop row.
        $kpis = array_values(array_filter($widgets, fn ($w) => $w['type'] === 'kpi'));
        $rest = array_values(array_filter($widgets, fn ($w) => $w['type'] !== 'kpi'));

        $out = [];
        $x = 0;
        $y = 0;
        $rowH = 0;
        foreach ([...$kpis, ...$rest] as $w) {
            $width = $this->width($w, $cols);
            $height = $w['type'] === 'kpi' ? 2 : max(3, min(6, $w['position']['h']));
            if ($x + $width > $cols) {
                $x = 0;
                $y += $rowH;
                $rowH = 0;
            }
            $out[] = ['id' => $w['id'], 'x' => $x, 'y' => $y, 'w' => $width, 'h' => $height, 'section' => $w['section']];
            $x += $width;
            $rowH = max($rowH, $height);
        }

        return $out;
    }

    private function width(array $w, int $cols): int
    {
        if ($w['type'] === 'kpi') {
            return intdiv($cols, 2);
        }
        if ($cols === 4) {
            return 4;
        }
        // Tablet: half width only for widgets that were at most a third of desktop.
        return $w['position']['w'] <= 4 ? intdiv($cols, 2) : $cols;
    }
}
