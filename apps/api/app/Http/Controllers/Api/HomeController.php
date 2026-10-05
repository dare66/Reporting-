<?php

namespace App\Http\Controllers\Api;

use App\Domain\Analytics\Format;
use App\Domain\Analytics\InsightEngine;
use App\Domain\Analytics\KpiService;
use App\Http\Controllers\Controller;
use App\Models\ActionRequest;
use App\Models\AlertRule;
use App\Models\Anomaly;
use App\Models\AppNotification;
use App\Models\Bookmark;
use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\DriftEvent;
use App\Models\Incident;
use App\Models\Insight;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The intelligent home screen: decides what deserves attention instead of
 * showing static tiles. Shared by web and mobile.
 */
class HomeController extends Controller
{
    public const PULSE = ['revenue.revenue', 'applications.total_applications', 'decisions.sla_compliance', 'applications.high_risk_applications'];

    public function show(Request $request, KpiService $kpis, InsightEngine $engine): JsonResponse
    {
        $user = $request->user();
        $range = $request->query('range', 'last_30_days');
        $tz = $user->organisation->timezone ?? 'UTC';
        $hour = (int) now($tz)->format('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

        $pulse = [];
        $pulseError = null;
        try {
            $pulse = $kpis->cards(self::PULSE, $range, $user);
        } catch (Throwable $e) {
            report($e);
            $pulseError = 'We couldn\'t refresh your KPIs. The analytical store may be unavailable.';
        }

        $insights = Insight::orderByDesc('created_at')->limit(6)->get();
        if ($insights->isEmpty() || $insights->first()->created_at->lt(now()->subHours(6))) {
            try {
                $insights = collect($engine->generate($user, $range))->take(6);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $attention = [...$this->decisions($user), ...$this->attention($pulse)];

        return response()->json(['data' => [
            'greeting' => $greeting,
            'first_name' => explode(' ', $user->name)[0],
            'as_of' => now()->toIso8601String(),
            'range' => $range,
            'summary' => $this->summary($pulse, $attention),
            'attention' => $attention,
            'pulse' => $pulse,
            'pulse_error' => $pulseError,
            'insights' => $insights->values(),
            'recent_reports' => Report::where(fn ($q) => $q->where('status', 'published')->orWhere('owner_id', $user->id))->latest('updated_at')->limit(5)->get(['id', 'title', 'type', 'status', 'updated_at', 'current_version']),
            'favourite_dashboards' => Dashboard::whereIn('id', Bookmark::where('user_id', $user->id)->where('resource_type', 'dashboard')->pluck('resource_id'))
                ->orWhere('is_home', true)->get(['id', 'title', 'description', 'is_home']),
            'unread_notifications' => AppNotification::where('user_id', $user->id)->whereNull('read_at')->count(),
            'freshness' => [
                'data_as_of' => Dataset::max('freshness_at'),
                'last_sync_at' => DataSource::max('last_sync_at'),
                'failing_sources' => DataSource::where('status', 'error')->count(),
            ],
        ]]);
    }

    /**
     * What needs this person's decision or follow-up right now, before the KPI signals:
     * approvals waiting for them, serious open incidents, and unacknowledged breaking data changes.
     *
     * @return list<array<string, mixed>>
     */
    private function decisions(User $user): array
    {
        $items = [];
        if ($user->hasPermission('actions.approve')) {
            $waiting = ActionRequest::where('status', 'proposed')->where(fn ($q) => $q->whereNull('requested_by')->orWhere('requested_by', '!=', $user->id)
                ->orWhereIn('kind', ['incident', 'notify']))->latest()->get(['id', 'title']);
            if ($waiting->isNotEmpty()) {
                $items[] = ['kind' => 'approval', 'severity' => 'critical',
                    'title' => $waiting->count() === 1 ? 'An action is waiting for your approval' : "{$waiting->count()} actions are waiting for your approval",
                    'detail' => $waiting->first()->title, 'action' => ['label' => 'Review', 'link' => '/actions']];
            }
        }
        if ($user->hasPermission('actions.request') || $user->hasPermission('actions.approve')) {
            foreach (Incident::whereIn('status', ['open', 'investigating'])->whereIn('severity', ['critical', 'high'])
                // Approvers see every serious incident; others, theirs and the unassigned ones.
                ->when(! $user->hasPermission('actions.approve'), fn ($q) => $q->where(fn ($q) => $q->where('assignee_id', $user->id)->orWhereNull('assignee_id')))
                ->orderByDesc('number')->limit(2)->get() as $i) {
                $items[] = ['kind' => 'incident', 'severity' => $i->severity === 'critical' ? 'critical' : 'warning', 'title' => "{$i->reference} {$i->status}",
                    'detail' => $i->title, 'action' => ['label' => 'Open', 'link' => '/actions?incident='.$i->id]];
            }
        }
        if ($user->hasPermission('data.view')) {
            $drift = DriftEvent::where('status', 'open')->where('severity', 'critical')->count();
            if ($drift > 0) {
                $items[] = ['kind' => 'data', 'severity' => 'critical', 'title' => $drift === 1 ? 'A breaking data change needs a look' : "{$drift} breaking data changes need a look",
                    'detail' => 'Dashboards or metrics may show wrong numbers until it is checked.', 'action' => ['label' => 'Open Trust Center', 'link' => '/trust']];
            }
        }

        return $items;
    }

    /**
     * Ranked: critical issues, warnings, then wins.
     *
     * @param  list<array<string, mixed>>  $pulse  KPI cards
     * @return list<array<string, mixed>>
     */
    private function attention(array $pulse): array
    {
        $items = [];
        foreach (Anomaly::where('status', 'open')->where('period', '>=', now()->subDays(14))->orderByRaw('ABS(score) DESC')->limit(2)->get() as $a) {
            $items[] = ['kind' => 'anomaly', 'severity' => $a->severity === 'critical' ? 'critical' : 'warning',
                'title' => $a->evidence['label'].' anomaly', 'detail' => number_format(abs($a->score), 1).'σ '.$a->evidence['direction'].' normal on '.$a->period->format('j M'),
                'action' => ['label' => 'Investigate', 'link' => '/investigate?metric='.urlencode($a->metric_key).'&anomaly='.$a->id]];
        }
        foreach (AlertRule::where('is_active', true)->where('last_state', 'breached')->limit(3)->get() as $r) {
            $items[] = ['kind' => 'alert', 'severity' => 'warning', 'title' => $r->name, 'detail' => 'Alert threshold breached',
                'action' => ['label' => 'Investigate', 'link' => '/investigate?metric='.urlencode($r->semanticModel->key.'.'.$r->metric_key)]];
        }
        foreach ($pulse as $c) {
            if ($c['target_status'] === 'missed') {
                $items[] = ['kind' => 'kpi', 'severity' => 'warning', 'title' => $c['label'].' below target',
                    'detail' => Format::value($c['value'], $c['format']).' vs target '.Format::value($c['target'], $c['format']),
                    'action' => ['label' => 'Investigate', 'link' => '/investigate?metric='.urlencode($c['ref'])]];
            } elseif ($c['sentiment'] === 'negative' && abs($c['format'] === 'percent' ? ($c['change'] ?? 0) * 10 : ($c['change_pct'] ?? 0)) >= 0.05) {
                $items[] = ['kind' => 'kpi', 'severity' => 'warning', 'title' => $c['label'].' deteriorating',
                    'detail' => Format::change($c['change'], $c['change_pct'], $c['format']).' vs previous period',
                    'action' => ['label' => 'Investigate', 'link' => '/investigate?metric='.urlencode($c['ref'])]];
            } elseif ($c['target_status'] === 'met' || $c['sentiment'] === 'positive') {
                $items[] = ['kind' => 'win', 'severity' => 'positive', 'title' => $c['label'].($c['target_status'] === 'met' ? ' target achieved' : ' improving'),
                    'detail' => Format::value($c['value'], $c['format']).' ('.Format::change($c['change'], $c['change_pct'], $c['format']).')',
                    'action' => ['label' => 'View', 'link' => '/explore?metric='.urlencode($c['ref'])]];
            }
        }
        // Severity first; within warnings, breaches (targets, alerts, anomalies) outrank drifts.
        $rank = fn ($i) => match (true) {
            $i['severity'] === 'critical' => 0,
            $i['severity'] === 'warning' && (str_ends_with($i['title'], 'below target') || in_array($i['kind'], ['anomaly', 'alert'], true)) => 1,
            $i['severity'] === 'warning' => 2,
            default => 3,
        };
        usort($items, fn ($a, $b) => $rank($a) <=> $rank($b));

        // De-duplicate by metric link so one problem is not listed three times.
        $seen = [];

        return array_values(array_filter($items, function ($i) use (&$seen) {
            parse_str((string) parse_url($i['action']['link'], PHP_URL_QUERY), $q);
            $key = ($q['metric'] ?? $i['title']).'|'.$i['severity'];

            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $pulse
     * @param  list<array<string, mixed>>  $attention
     */
    private function summary(array $pulse, array $attention): string
    {
        if ($pulse === []) {
            return 'Live KPIs are unavailable right now; showing the latest saved insights.';
        }
        $negative = array_filter($pulse, fn ($c) => $c['sentiment'] === 'negative');
        $issues = array_filter($attention, fn ($a) => $a['severity'] !== 'positive');
        $state = count($negative) === 0 ? 'Overall business performance is healthy' : (count($negative) <= count($pulse) / 2 ? 'Overall business performance is stable' : 'Overall business performance is under pressure');
        $focus = $issues ? ', but '.array_values($issues)[0]['title'].' requires investigation' : '';

        return $state.$focus.'. '.(count($pulse) - count($negative)).' of '.count($pulse).' headline KPIs are holding or improving.';
    }
}
