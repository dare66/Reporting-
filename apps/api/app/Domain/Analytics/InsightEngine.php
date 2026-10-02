<?php

namespace App\Domain\Analytics;

use App\Models\Anomaly;
use App\Models\Insight;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Produces ranked, evidence-backed insights from deterministic analysis.
 * No language model is involved: each sentence is rendered from computed
 * facts, and each insight stores the queries that produced those facts.
 */
class InsightEngine
{
    public const HEADLINE_KPIS = [
        'revenue.revenue', 'applications.total_applications', 'decisions.sla_compliance',
        'decisions.approval_rate', 'decisions.avg_processing_days', 'applications.high_risk_applications',
    ];

    public function __construct(private readonly KpiService $kpis, private readonly RootCauseService $rootCause) {}

    /** @return array<int, Insight> */
    public function generate(User $user, string $range = 'last_30_days'): array
    {
        $cards = collect($this->kpis->cards(self::HEADLINE_KPIS, $range, $user));
        $found = [];

        // 1. Material KPI movements.
        foreach ($cards as $c) {
            $material = $c['format'] === 'percent' ? abs($c['change'] ?? 0) >= 0.01 : abs($c['change_pct'] ?? 0) >= 0.03;
            if (! $material) {
                continue;
            }
            $verb = $c['direction'] === 'up' ? 'increased' : 'decreased';
            $found[] = [
                'kind' => 'change', 'metric_key' => $c['ref'],
                'severity' => $c['sentiment'] === 'negative' ? 'warning' : 'positive',
                'title' => "{$c['label']} {$verb} ".ltrim(Format::change($c['change'], $c['change_pct'], $c['format']), '+−'),
                'body' => "{$c['label']} was ".Format::value($c['value'], $c['format']).' over '.Format::period($c['period']['label']).', versus '.Format::value($c['previous'], $c['format']).' in the previous period.',
                'evidence' => ['calculation' => 'current − previous', 'periods' => [$c['period'], $c['previous_period']], 'values' => [$c['value'], $c['previous']], 'queries' => $c['evidence']],
                'score' => abs($c['format'] === 'percent' ? ($c['change'] ?? 0) * 10 : ($c['change_pct'] ?? 0)) * ($c['sentiment'] === 'negative' ? 1.5 : 1),
            ];
        }

        // 2. Drivers behind the most concerning KPI.
        $worst = $cards->filter(fn ($c) => $c['sentiment'] === 'negative' && $c['format'] === 'percent')->sortBy('change')->first();
        if ($worst) {
            try {
                $rc = $this->rootCause->explain($worst['ref'], $range, $user);
                $inst = collect($rc['dimensions'])->firstWhere('key', 'institution');
                if ($inst && ($rc['change'] ?? 0) != 0) {
                    $aligned = collect($inst['members'])->filter(fn ($m) => $m['impact'] * $rc['change'] > 0)->take(3);
                    $share = $aligned->sum('impact') / $rc['change'];
                    if ($aligned->count() === 3 && $share > 0.3) {
                        $found[] = [
                            'kind' => 'driver', 'metric_key' => $worst['ref'], 'severity' => 'warning',
                            'title' => '3 institutions account for '.round($share * 100).'% of the '.$worst['label'].' deterioration',
                            'body' => $aligned->pluck('member')->implode(', ').' together drove '.Format::change($aligned->sum('impact'), null, $worst['format']).' of the '.Format::change($rc['change'], null, $worst['format']).' change.',
                            'evidence' => ['method' => $rc['method'], 'members' => $aligned->values()->all(), 'total_change' => $rc['change'], 'queries' => $rc['evidence']],
                            'score' => 2.0 + $share,
                        ];
                    }
                }
                if ($d = $rc['drivers'][0] ?? null) {
                    $found[] = [
                        'kind' => 'driver', 'metric_key' => $worst['ref'], 'severity' => 'warning',
                        'title' => "{$d['dimension_label']} “{$d['member']}” is the leading driver of {$worst['label']}",
                        'body' => "{$worst['label']} for {$d['member']} moved from ".Format::value($d['previous_value'], $worst['format']).' to '.Format::value($d['current_value'], $worst['format'])
                            .', accounting for '.round(($d['impact_share'] ?? 0) * 100).'% of the overall change.',
                        'evidence' => ['method' => $rc['method'], 'driver' => $d, 'queries' => $rc['evidence']],
                        'score' => 1.8,
                    ];
                }
            } catch (Throwable $e) {
                logger()->info('insights.root_cause_skipped', ['error' => $e->getMessage()]);
            }
        }

        // 3. Demand outpacing capacity (year over year, removes seasonality).
        try {
            $yoy = collect($this->kpis->cards(['applications.total_applications', 'capacity.processing_capacity'], 'last_90_days', $user, [], 'previous_year'))->keyBy('ref');
            $d = $yoy['applications.total_applications']['change_pct'] ?? null;
            $c = $yoy['capacity.processing_capacity']['change_pct'] ?? null;
            if ($d !== null && $c !== null && $d - $c > 0.05) {
                $found[] = [
                    'kind' => 'capacity', 'metric_key' => 'applications.total_applications', 'severity' => 'warning',
                    'title' => 'Applications increased '.round($d * 100, 1).'% but processing capacity only '.round($c * 100, 1).'%',
                    'body' => 'Over the last 90 days versus the same period last year, demand grew faster than capacity. Potential SLA pressure detected.',
                    'evidence' => ['calculation' => 'year-over-year change, last 90 days', 'applications' => $yoy['applications.total_applications']['evidence'], 'capacity' => $yoy['capacity.processing_capacity']['evidence']],
                    'score' => 2.5,
                ];
            }
        } catch (Throwable $e) {
            logger()->info('insights.capacity_skipped', ['error' => $e->getMessage()]);
        }

        // 4. Open anomalies.
        foreach (Anomaly::where('status', 'open')->where('period', '>=', now()->subDays(30))->orderByRaw('ABS(score) DESC')->limit(2)->get() as $a) {
            $found[] = [
                'kind' => 'anomaly', 'metric_key' => $a->metric_key, 'severity' => $a->severity === 'critical' ? 'critical' : 'warning',
                'title' => "{$a->evidence['label']} is ".number_format(abs($a->score), 1).' standard deviations '.$a->evidence['direction'].' normal',
                'body' => 'Detected '.$a->period->format('j M Y').': expected '.Format::value($a->expected, $a->evidence['format']).', actual '.Format::value($a->actual, $a->evidence['format']).'.',
                'evidence' => ['anomaly_id' => $a->id, 'method' => $a->method, 'expected' => $a->expected, 'actual' => $a->actual, 'query' => $a->evidence['query'] ?? null],
                'score' => min(3.5, abs($a->score) / 1.5),
            ];
        }

        usort($found, fn ($a, $b) => $b['score'] <=> $a['score']);

        return DB::transaction(function () use ($found, $range) {
            Insight::where('generated_by', 'insight-engine')->delete();
            $period = \App\Domain\Query\TimeRange::resolve($range);

            return array_map(fn ($i) => Insight::create([
                'metric_key' => $i['metric_key'], 'kind' => $i['kind'], 'severity' => $i['severity'],
                'title' => $i['title'], 'body' => $i['body'], 'evidence' => $i['evidence'] + ['rank_score' => round($i['score'], 4)],
                'generated_by' => 'insight-engine', 'period_start' => $period->from, 'period_end' => $period->to->subDay(),
            ]), array_slice($found, 0, 8));
        });
    }
}
