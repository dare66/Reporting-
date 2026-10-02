<?php

namespace Tests\Unit;

use App\Domain\Analytics\Format;
use App\Domain\Analytics\KpiService;
use App\Domain\Dashboards\LayoutReflow;
use App\Domain\Identity\Totp;
use PHPUnit\Framework\TestCase;

class TotpAndReflowTest extends TestCase
{
    public function test_totp_matches_rfc6238_vector(): void
    {
        // RFC 6238 SHA1 test secret "12345678901234567890" → base32
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', Totp::code($secret, 59));
        $this->assertTrue(Totp::verify($secret, '287082', 59));
        $this->assertFalse(Totp::verify($secret, '000000', 59));
    }

    public function test_mobile_reflow_stacks_and_pairs_kpis(): void
    {
        $w = fn ($id, $type, $x, $y, $width, $p) => ['id' => $id, 'type' => $type, 'section' => null, 'priority' => $p, 'position' => ['x' => $x, 'y' => $y, 'w' => $width, 'h' => 4]];
        $layouts = (new LayoutReflow)->layouts([
            $w('chart', 'chart', 0, 2, 8, 50), $w('k1', 'kpi', 0, 0, 3, 10), $w('k2', 'kpi', 3, 0, 3, 20), $w('k3', 'kpi', 6, 0, 3, 30),
        ]);
        $m = collect($layouts['mobile'])->keyBy('id');
        $this->assertSame([0, 0, 2, 2], [$m['k1']['x'], $m['k1']['y'], $m['k2']['x'], $m['k2']['w']]);
        $this->assertSame(0, $m['k3']['x']);
        $this->assertSame(4, $m['chart']['w']);
        $this->assertGreaterThan($m['k3']['y'], $m['chart']['y']);
        foreach ($layouts['tablet'] as $item) {
            $this->assertLessThanOrEqual(8, $item['x'] + $item['w']);
        }
    }

    public function test_change_semantics_respect_direction_of_goodness(): void
    {
        $c = KpiService::change(0.86, 0.91, true);
        $this->assertSame('down', $c['direction']);
        $this->assertSame('negative', $c['sentiment']);
        $this->assertSame('positive', KpiService::change(9.0, 11.0, false)['sentiment']);
        $this->assertSame('−5.0 pts', Format::change(-0.05, null, 'percent'));
        $this->assertSame('RM 8.42M', Format::value(8_420_000, 'currency'));
    }
}
