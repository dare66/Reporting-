<?php

namespace App\Domain\Metrics;

use App\Models\AlertRule;
use App\Models\Dashboard;
use App\Models\Report;
use App\Models\User;

/**
 * Where each metric is used: dashboards, reports and alert rules. Items the
 * person may not open are counted but not named, so usage never reveals the
 * titles of someone else's private work.
 *
 * @phpstan-type Item array{id: string, title: string}
 * @phpstan-type Usage array{dashboards: list<Item>, reports: list<Item>, alerts: list<Item>, hidden: int, total: int}
 */
class MetricUsage
{
    /**
     * @param  User|null  $user  whose access decides what is named; null for a system view that names everything
     * @return array<string, Usage> keyed by metric ref "model.metric"
     */
    public function index(?User $user): array
    {
        $usage = [];
        $seen = [];
        $add = function (string $ref, string $kind, string $id, string $title, bool $visible) use (&$usage, &$seen) {
            $usage[$ref] ??= ['dashboards' => [], 'reports' => [], 'alerts' => [], 'hidden' => 0, 'total' => 0];
            if (isset($seen[$ref.$kind.$id])) {
                return;
            }
            $seen[$ref.$kind.$id] = true;
            $usage[$ref]['total']++;
            if ($visible) {
                $usage[$ref][$kind][] = ['id' => $id, 'title' => $title];
            } else {
                $usage[$ref]['hidden']++;
            }
        };

        foreach (Dashboard::with('widgets:id,dashboard_id,query')->get(['id', 'title', 'visibility', 'owner_id']) as $d) {
            $visible = $user === null || $d->visibility === 'organisation' || $d->owner_id === $user->id;
            foreach ($d->widgets as $w) {
                foreach ((array) ($w->query['metrics'] ?? []) as $metric) {
                    if (isset($w->query['model'])) {
                        $add("{$w->query['model']}.{$metric}", 'dashboards', $d->id, $d->title, $visible);
                    }
                }
            }
        }

        $allReports = $user === null || $user->hasPermission('reports.publish');
        foreach (Report::with('sections:id,report_id,content')->get(['id', 'title', 'status', 'owner_id']) as $r) {
            $visible = $allReports || $r->owner_id === $user->id || in_array($r->status, ['published', 'archived'], true);
            foreach ($r->sections as $s) {
                $bp = $s->content['blueprint'] ?? [];
                foreach (array_filter([...(array) ($bp['metrics'] ?? []), $bp['metric'] ?? null]) as $ref) {
                    $add((string) $ref, 'reports', $r->id, $r->title, $visible);
                }
            }
        }

        foreach (AlertRule::with('semanticModel:id,key')->get(['id', 'name', 'metric_key', 'semantic_model_id']) as $a) {
            if ($a->semanticModel) {
                $add("{$a->semanticModel->key}.{$a->metric_key}", 'alerts', $a->id, $a->name, $user === null || $user->hasPermission('alerts.view'));
            }
        }

        return $usage;
    }
}
