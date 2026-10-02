<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ScheduleCalculator
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'quarterly'];

    public static function next(string $frequency, string $timeOfDay, ?CarbonImmutable $after = null, string $tz = 'UTC'): CarbonImmutable
    {
        if (! in_array($frequency, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException("Unsupported schedule frequency {$frequency}");
        }
        $after ??= CarbonImmutable::now($tz);
        [$h, $m] = array_map('intval', explode(':', $timeOfDay));
        $candidate = match ($frequency) {
            'daily' => $after->setTime($h, $m),
            'weekly' => $after->startOfWeek()->setTime($h, $m),
            'monthly' => $after->startOfMonth()->setTime($h, $m),
            default => $after->startOfQuarter()->setTime($h, $m),
        };
        while ($candidate <= $after) {
            $candidate = match ($frequency) {
                'daily' => $candidate->addDay(),
                'weekly' => $candidate->addWeek(),
                'monthly' => $candidate->addMonthNoOverflow(),
                default => $candidate->addMonthsNoOverflow(3),
            };
        }

        return $candidate->utc();
    }
}
