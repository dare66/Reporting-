<?php

namespace App\Domain\Reports\Export;

/**
 * Report themes shared by every renderer so PDF, PPTX and HTML look like one product.
 *
 * @phpstan-type Palette array{ink: string, paper: string, accent: string, text: string, muted: string, positive: string, negative: string, series: list<string>}
 */
final class Theme
{
    public const THEMES = [
        'executive' => ['ink' => '0E1420', 'paper' => 'FBFAF7', 'accent' => 'E8B04B', 'text' => '1B2230', 'muted' => '6B7385', 'positive' => '2E9E6A', 'negative' => 'D1495B', 'series' => ['1F6FEB', 'E8B04B', '2E9E6A', 'A371F7', 'D1495B', '3FB8AF']],
        'corporate' => ['ink' => '12263F', 'paper' => 'FFFFFF', 'accent' => '2F80ED', 'text' => '1B2230', 'muted' => '667085', 'positive' => '12B76A', 'negative' => 'F04438', 'series' => ['2F80ED', '12263F', '56CCF2', 'F2994A', '9B51E0', '27AE60']],
        'financial' => ['ink' => '0B2E2A', 'paper' => 'FAFAF6', 'accent' => '3FB68B', 'text' => '102A26', 'muted' => '5F6F6B', 'positive' => '3FB68B', 'negative' => 'C8553D', 'series' => ['0B6E4F', '3FB68B', 'C9A227', '2D6A8F', 'C8553D', '8E7DBE']],
        'operations' => ['ink' => '1A1D29', 'paper' => 'F7F8FA', 'accent' => 'FF7A45', 'text' => '1A1D29', 'muted' => '6E7387', 'positive' => '22A06B', 'negative' => 'E5484D', 'series' => ['FF7A45', '3E63DD', '22A06B', 'AB4ABA', 'E5484D', '0091FF']],
        'government' => ['ink' => '14213D', 'paper' => 'FFFFFF', 'accent' => 'B08D57', 'text' => '14213D', 'muted' => '5C677D', 'positive' => '2A7F62', 'negative' => 'A4243B', 'series' => ['14213D', 'B08D57', '2A7F62', '5C80BC', 'A4243B', '7D8CA3']],
    ];

    /** @return Palette */
    public static function get(?string $name): array
    {
        return self::THEMES[$name] ?? self::THEMES['executive'];
    }
}
