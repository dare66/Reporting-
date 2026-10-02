<?php

namespace Tests\Unit;

use App\Domain\Query\TimeRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class TimeRangeTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        $this->now = CarbonImmutable::parse('2026-10-02');
    }

    public function test_month_to_date_compares_with_same_span_of_previous_month(): void
    {
        $r = TimeRange::resolve('this_month', $this->now);
        $this->assertSame(['2026-10-01', '2026-10-02'], [$r->from->toDateString(), $r->to->subDay()->toDateString()]);
        $p = $r->previous();
        $this->assertSame(['2026-09-01', '2026-09-03'], [$p->from->toDateString(), $p->to->toDateString()]);
    }

    public function test_year_to_date_previous_is_same_span_last_year(): void
    {
        $p = TimeRange::resolve('year_to_date', $this->now)->previous();
        $this->assertSame('2025-01-01', $p->from->toDateString());
        $this->assertSame('2025-10-03', $p->to->toDateString());
    }

    public function test_rolling_days_shift_by_length(): void
    {
        $r = TimeRange::resolve('last_30_days', $this->now);
        $this->assertSame('2026-09-03', $r->from->toDateString());
        $this->assertSame('2026-08-04', $r->previous()->from->toDateString());
        $this->assertTrue($r->previous()->to->equalTo($r->from));
    }

    public function test_custom_range_is_inclusive_of_end_date(): void
    {
        $r = TimeRange::resolve(['from' => '2026-01-01', 'to' => '2026-01-31'], $this->now);
        $this->assertSame('2026-02-01', $r->to->toDateString());
        $this->assertSame('2025-12-01', $r->previous()->from->toDateString());
    }
}
