<?php

namespace App\Domain\Analytics;

use App\Domain\Query\QueryService;
use App\Domain\Query\SemanticQuery;
use App\Domain\Query\TimeRange;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What-if simulation for demand vs. processing capacity.
 *
 * The SLA response is not assumed: it is fitted by least squares on the last
 * 52 weeks of observed (utilisation → SLA) pairs, and the fit statistics are
 * returned so users can judge how far to trust the projection.
 *
 * @phpstan-type Baseline array{period: array<string, mixed>, applications: float, decisions: float, capacity: float, officers: float, utilisation: ?float, sla_compliance: mixed, avg_processing_days: mixed, revenue: float, revenue_per_application: float}
 * @phpstan-type SlaFit array{intercept: float, slope: float, r_squared: ?float, observations: int, utilisation_range?: array{0: float, 1: float}, method: string}
 */
class ScenarioService
{
    public function __construct(private readonly QueryService $queries) {}

    /**
     * @param  array{demand_change_pct?: float, officer_change_pct?: float, productivity_change_pct?: float}  $assumptions
     * @return array{assumptions: array<string, float>, baseline: Baseline, projected: array<string, int|float|null>, sla_target: float, model: SlaFit, extrapolated: bool, caveats: list<string>}
     */
    public function simulate(array $assumptions, User $user): array
    {
        $demand = (float) ($assumptions['demand_change_pct'] ?? 0) / 100;
        $officers = (float) ($assumptions['officer_change_pct'] ?? 0) / 100;
        $productivity = (float) ($assumptions['productivity_change_pct'] ?? 0) / 100;

        $baseline = $this->baseline($user);
        $fit = $this->fitSlaModel($user);

        $apps = $baseline['applications'] * (1 + $demand);
        $capacity = $baseline['capacity'] * (1 + $officers) * (1 + $productivity);
        $utilisation = $capacity > 0 ? $apps / $capacity : null;
        // Anchor on the observed baseline and apply the fitted sensitivity to the
        // change in utilisation, so a zero-change scenario reproduces today exactly.
        $baseU = $baseline['utilisation'];
        $baseSla = $baseline['sla_compliance'];
        $sla = ($utilisation === null || $baseU === null || $baseSla === null) ? null
            : $this->clamp($baseSla + $fit['slope'] * ($utilisation - $baseU));
        $range = $fit['utilisation_range'] ?? null;
        $extrapolated = $utilisation !== null && $range !== null && ($utilisation < $range[0] || $utilisation > $range[1]);
        $revenue = $baseline['revenue_per_application'] * $apps;

        // Officers needed to bring projected SLA back to target.
        $target = 0.9;
        $requiredOfficers = null;
        if ($fit['slope'] < 0 && $utilisation !== null && $baseU !== null && $baseSla !== null) {
            $uStar = $baseU + ($target - $baseSla) / $fit['slope'];
            if ($uStar > 0) {
                $capNeeded = $apps / $uStar;
                $perOfficer = $baseline['capacity'] / max(1, $baseline['officers']) * (1 + $productivity);
                $requiredOfficers = (int) ceil($capNeeded / max(1e-9, $perOfficer));
            }
        }

        $projected = [
            'applications' => round($apps),
            'capacity' => round($capacity),
            'officers' => round($baseline['officers'] * (1 + $officers), 1),
            'utilisation' => $utilisation === null ? null : round($utilisation, 4),
            'sla_compliance' => $sla === null ? null : round($sla, 4),
            'revenue' => round($revenue, 2),
            'required_officers_for_target' => $requiredOfficers,
            'additional_officers_needed' => $requiredOfficers === null ? null : max(0, $requiredOfficers - (int) round($baseline['officers'] * (1 + $officers))),
        ];

        return [
            'assumptions' => ['demand_change_pct' => $demand * 100, 'officer_change_pct' => $officers * 100, 'productivity_change_pct' => $productivity * 100],
            'baseline' => $baseline,
            'projected' => $projected,
            'sla_target' => $target,
            'model' => $fit,
            'extrapolated' => $extrapolated,
            'caveats' => array_values(array_filter([
                $extrapolated ? 'Projected utilisation is outside the range observed in the last year; treat the SLA projection as directional.' : null,
                ($fit['r_squared'] ?? 1) < 0.3 ? 'Utilisation explains less than 30% of weekly SLA variation; other factors matter.' : null,
            ])),
        ];
    }

    /** @return Baseline */
    private function baseline(User $user): array
    {
        $range = TimeRange::resolve('last_30_days');
        $one = fn (string $model, array $metrics) => $this->queries->run($model, new SemanticQuery(metrics: $metrics, timeRange: $range), $user)->first();
        $a = $one('applications', ['total_applications']);
        $d = $one('decisions', ['sla_compliance', 'avg_processing_days', 'decided_applications']);
        $c = $one('capacity', ['processing_capacity', 'officers']);
        $r = $one('revenue', ['revenue']);
        $apps = (float) $a['total_applications'];

        return [
            'period' => $range->toArray(),
            'applications' => $apps,
            'decisions' => (float) $d['decided_applications'],
            'capacity' => (float) $c['processing_capacity'],
            'officers' => round((float) $c['officers'], 1),
            'utilisation' => $c['processing_capacity'] > 0 ? round($apps / $c['processing_capacity'], 4) : null,
            'sla_compliance' => $d['sla_compliance'],
            'avg_processing_days' => $d['avg_processing_days'],
            'revenue' => (float) $r['revenue'],
            'revenue_per_application' => $apps > 0 ? (float) $r['revenue'] / $apps : 0.0,
        ];
    }

    /**
     * OLS of weekly SLA (by decision week) on utilisation two weeks earlier (typical processing lag).
     *
     * @return SlaFit
     */
    private function fitSlaModel(User $user): array
    {
        $end = CarbonImmutable::now()->startOfWeek();
        $range = new TimeRange($end->subWeeks(54), $end, 'fit');
        $weekly = fn (string $model, string $metric) => collect($this->queries->run($model, new SemanticQuery(metrics: [$metric], grain: 'week', timeRange: $range, limit: 200), $user)->rows)
            ->mapWithKeys(fn ($r) => [$r['period'] => $r[$metric]]);
        $apps = $weekly('applications', 'total_applications');
        $cap = $weekly('capacity', 'processing_capacity');
        $sla = $weekly('decisions', 'sla_compliance');

        $x = [];
        $y = [];
        foreach ($sla as $week => $s) {
            $lagged = CarbonImmutable::parse($week)->subWeeks(2)->toDateString();
            if ($s !== null && isset($apps[$lagged], $cap[$lagged]) && $cap[$lagged] > 0) {
                $x[] = $apps[$lagged] / $cap[$lagged];
                $y[] = (float) $s;
            }
        }
        $n = count($x);
        if ($n < 8) {
            return ['intercept' => 0.95, 'slope' => 0.0, 'r_squared' => null, 'observations' => $n, 'method' => 'insufficient_history'];
        }
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sxy = 0;
        $sxx = 0;
        $syy = 0;
        foreach ($x as $i => $xi) {
            $sxy += ($xi - $mx) * ($y[$i] - $my);
            $sxx += ($xi - $mx) ** 2;
            $syy += ($y[$i] - $my) ** 2;
        }
        $slope = $sxx > 0 ? $sxy / $sxx : 0;
        $intercept = $my - $slope * $mx;

        return [
            'intercept' => round($intercept, 6),
            'slope' => round($slope, 6),
            'r_squared' => $sxx > 0 && $syy > 0 ? round(($sxy ** 2) / ($sxx * $syy), 4) : null,
            'observations' => $n,
            'utilisation_range' => [round(min($x), 3), round(max($x), 3)],
            'method' => 'ols_weekly_sla_on_lagged_utilisation',
        ];
    }

    private function clamp(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}
