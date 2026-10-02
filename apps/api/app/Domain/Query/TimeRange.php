<?php

namespace App\Domain\Query;

use Carbon\CarbonImmutable;

/**
 * Half-open date interval [from, to). Resolves business phrases
 * ("this_month", "last_12_months") deterministically against a reference date.
 */
final class TimeRange
{
    public const PRESETS = [
        'today', 'yesterday', 'last_7_days', 'last_30_days', 'last_90_days', 'this_week', 'last_week',
        'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year',
        'last_6_months', 'last_12_months', 'last_24_months', 'month_to_date', 'year_to_date',
    ];

    /**
     * @param  array{0: string, 1: int}|null  $step  calendar step used to derive the comparable prior period,
     *                                               e.g. ['month', 1] for month-to-date; null = shift by length in days
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $label,
        public readonly ?array $step = null,
    ) {}

    /** @param string|array{from?: string, to?: string} $spec */
    public static function resolve(string|array $spec, ?CarbonImmutable $now = null): self
    {
        $now = ($now ?? CarbonImmutable::now())->startOfDay();
        if (is_array($spec)) {
            if (empty($spec['from']) || empty($spec['to'])) {
                throw new QueryValidationException('A custom time range needs both from and to.');
            }
            $from = CarbonImmutable::parse($spec['from'])->startOfDay();
            $to = CarbonImmutable::parse($spec['to'])->startOfDay()->addDay(); // inclusive end date from users
            if ($to <= $from) {
                throw new QueryValidationException('Time range end must be after its start.');
            }

            return new self($from, $to, $from->format('j M Y').' – '.$to->subDay()->format('j M Y'), ['day', (int) $from->diffInDays($to)]);
        }

        $tomorrow = $now->addDay();
        $som = $now->startOfMonth();
        $soq = $now->startOfQuarter();

        return match ($spec) {
            'today' => new self($now, $tomorrow, 'Today', ['day', 1]),
            'yesterday' => new self($now->subDay(), $now, 'Yesterday', ['day', 1]),
            'last_7_days' => new self($now->subDays(6), $tomorrow, 'Last 7 days', ['day', 7]),
            'last_30_days' => new self($now->subDays(29), $tomorrow, 'Last 30 days', ['day', 30]),
            'last_90_days' => new self($now->subDays(89), $tomorrow, 'Last 90 days', ['day', 90]),
            'this_week' => new self($now->startOfWeek(), $tomorrow, 'This week (to date)', ['day', 7]),
            'last_week' => new self($now->startOfWeek()->subWeek(), $now->startOfWeek(), 'Last week', ['day', 7]),
            'this_month', 'month_to_date' => new self($som, $tomorrow, $now->format('F Y').' (to date)', ['month', 1]),
            'last_month' => new self($som->subMonth(), $som, $som->subMonth()->format('F Y'), ['month', 1]),
            'this_quarter' => new self($soq, $tomorrow, 'Q'.$now->quarter.' '.$now->year.' (to date)', ['month', 3]),
            'last_quarter' => new self($soq->subQuarter(), $soq, 'Q'.$soq->subQuarter()->quarter.' '.$soq->subQuarter()->year, ['month', 3]),
            'this_year', 'year_to_date' => new self($now->startOfYear(), $tomorrow, $now->year.' year to date', ['month', 12]),
            'last_year' => new self($now->startOfYear()->subYear(), $now->startOfYear(), (string) ($now->year - 1), ['month', 12]),
            'last_6_months' => new self($som->subMonths(5), $tomorrow, 'Last 6 months', ['month', 6]),
            'last_12_months' => new self($som->subMonths(11), $tomorrow, 'Last 12 months', ['month', 12]),
            'last_24_months' => new self($som->subMonths(23), $tomorrow, 'Last 24 months', ['month', 24]),
            default => throw new QueryValidationException("Unknown time range '{$spec}'."),
        };
    }

    /**
     * The comparable prior period. Partial calendar periods compare against the
     * same elapsed span of the previous period (month-to-date vs prior month-to-date),
     * which is what executives expect and avoids misleading "drops".
     */
    public function previous(): self
    {
        [$unit, $n] = $this->step ?? ['day', (int) $this->from->diffInDays($this->to)];
        $from = $unit === 'month' ? $this->from->subMonthsNoOverflow($n) : $this->from->subDays($n);
        $to = $unit === 'month' ? $this->to->subMonthsNoOverflow($n) : $this->to->subDays($n);

        return new self($from, min($to, $this->from), 'Previous period', $this->step);
    }

    public function previousYear(): self
    {
        return new self($this->from->subYear(), $this->to->subYear(), 'Same period last year', $this->step);
    }

    /** @return array{from: string, to: string, label: string} inclusive end for display */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->subDay()->toDateString(), 'label' => $this->label];
    }
}
