<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;

final class ScheduleCalculator
{
    public static function next(string $frequency, string $timeOfDay, ?CarbonImmutable $after = null, string $tz = 'UTC'): CarbonImmutable
    {
        $after ??= CarbonImmutable::now($tz);
        [$h, $m] = array_map('intval', explode(':', $timeOfDay));
        $candidate = match ($frequency) {
            'daily' => $after->setTime($h, $m),
            'weekly' => $after->startOfWeek()->setTime($h, $m),
            'monthly' => $after->startOfMonth()->setTime($h, $m),
            'quarterly' => $after->startOfQuarter()->setTime($h, $m),
        };
        while ($candidate <= $after) {
            $candidate = match ($frequency) {
                'daily' => $candidate->addDay(),
                'weekly' => $candidate->addWeek(),
                'monthly' => $candidate->addMonthNoOverflow(),
                'quarterly' => $candidate->addMonthsNoOverflow(3),
            };
        }

        return $candidate->utc();
    }
}
