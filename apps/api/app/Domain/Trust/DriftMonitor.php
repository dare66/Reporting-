<?php

namespace App\Domain\Trust;

use App\Domain\AutoBi\DataTrust;
use App\Domain\Metrics\MetricUsage;
use App\Domain\Notifications\Notifier;
use App\Domain\Query\Expression\ExpressionParser;
use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\DatasetSnapshot;
use App\Models\DriftEvent;
use App\Models\SemanticModel;
use App\Models\User;
use Throwable;

/**
 * Watches every dataset for drift. After each load or re-profile it records a
 * snapshot, compares it with the previous one, works out what each changed
 * column feeds (metrics, dimensions, and through them dashboards and reports),
 * raises the severity of changes that break something in use, and tells the
 * people who own the affected metrics and the data team.
 *
 * @phpstan-import-type Change from SchemaSnapshot
 *
 * @phpstan-type Item array{id: string, title: string}
 * @phpstan-type Impact array{metrics: list<array{ref: string, label: string, status: string}>, dimensions: list<array{ref: string, label: string}>, dashboards: list<Item>, reports: list<Item>, alerts: list<Item>, hidden: int}
 */
class DriftMonitor
{
    /** Changes that break a query when the column is in use. */
    private const BREAKING = ['column_removed', 'column_renamed', 'type_changed'];

    public function __construct(
        private readonly SchemaSnapshot $snapshots,
        private readonly DataTrust $trust,
        private readonly MetricUsage $usage,
        private readonly Notifier $notifier,
    ) {}

    public function record(Dataset $dataset, string $trigger): DatasetSnapshot
    {
        $dataset->loadMissing('fields');
        $previous = DatasetSnapshot::where('dataset_id', $dataset->id)->orderByDesc('taken_at')->orderByDesc('id')->first();
        $columns = $this->snapshots->columns($dataset->fields->map(fn ($f) => ['name' => $f->name, 'data_type' => $f->data_type, 'profile' => $f->profile ?? []]));
        $snapshot = DatasetSnapshot::create([
            'organisation_id' => $dataset->organisation_id, 'dataset_id' => $dataset->id, 'trigger' => $trigger,
            'row_count' => (int) $dataset->row_count, 'columns' => $columns, 'taken_at' => now(),
        ]);

        $events = [];
        if ($previous) {
            $changes = $this->snapshots->diff($previous->columns, $columns, $previous->row_count, (int) $dataset->row_count);
            $used = $changes ? $this->usedColumns($dataset) : [];
            foreach ($changes as $c) {
                $events[] = DriftEvent::create([
                    'organisation_id' => $dataset->organisation_id, 'dataset_id' => $dataset->id, 'snapshot_id' => $snapshot->id,
                    'kind' => $c['kind'], 'column' => $c['column'], 'severity' => $this->severity($c, $used, $previous->columns),
                    'message' => $c['message'], 'before' => $c['before'], 'after' => $c['after'], 'detected_at' => now(),
                ]);
            }
        }

        $trust = $this->trust->assess($dataset->fresh(['fields', 'dataSource']));
        $snapshot->update(['trust_score' => $trust['score'], 'trust' => $trust]);
        $this->notify($dataset, $events);

        return $snapshot;
    }

    /**
     * What a column of a dataset feeds; with no column, what the whole dataset feeds.
     *
     * @return Impact
     */
    public function impact(Dataset $dataset, ?string $column, ?User $viewer): array
    {
        $metrics = [];
        $dimensions = [];
        $dimensionRefs = [];
        $models = SemanticModel::with(['measures', 'metrics', 'dimensions', 'relationships'])->get();
        foreach ($models as $model) {
            $isBase = $model->base_dataset_id === $dataset->id;
            foreach ($model->dimensions as $d) {
                if ($d->dataset_id === $dataset->id && ($column === null || $d->field === $column)) {
                    $dimensions[] = ['ref' => "{$model->key}.{$d->key}", 'label' => $d->label];
                    $dimensionRefs[$model->key][] = $d->key;
                }
            }
            if (! $isBase) {
                continue;
            }
            $hit = $model->measures->filter(fn ($m) => $column === null || $m->field === $column || collect($m->filters ?? [])->contains('field', $column))->pluck('key')->all();
            if ($hit === []) {
                continue;
            }
            $parser = new ExpressionParser($model->measures->pluck('key')->all());
            foreach ($model->metrics as $metric) {
                try {
                    $refs = $parser->parse($metric->expression)->references();
                } catch (Throwable) {
                    $refs = [];
                }
                if (array_intersect($refs, $hit)) {
                    $metrics[] = ['ref' => "{$model->key}.{$metric->key}", 'label' => $metric->label, 'status' => $metric->status];
                }
            }
        }

        $impact = ['metrics' => $metrics, 'dimensions' => $dimensions, 'dashboards' => [], 'reports' => [], 'alerts' => [], 'hidden' => 0];
        $usage = $this->usage->index($viewer);
        $seen = [];
        foreach ($metrics as $m) {
            foreach (['dashboards', 'reports', 'alerts'] as $kind) {
                foreach ($usage[$m['ref']][$kind] ?? [] as $item) {
                    if (! isset($seen[$kind.$item['id']])) {
                        $seen[$kind.$item['id']] = true;
                        $impact[$kind][] = $item;
                    }
                }
            }
            $impact['hidden'] += $usage[$m['ref']]['hidden'] ?? 0;
        }
        // Dashboards that group or filter by an affected dimension break too.
        if ($dimensionRefs) {
            foreach (Dashboard::with('widgets:id,dashboard_id,query')->get(['id', 'title', 'visibility', 'owner_id']) as $d) {
                $uses = $d->widgets->contains(fn ($w) => array_intersect((array) ($w->query['dimensions'] ?? []), $dimensionRefs[$w->query['model'] ?? ''] ?? []) !== []);
                if ($uses && ! isset($seen['dashboards'.$d->id])) {
                    $seen['dashboards'.$d->id] = true;
                    if ($viewer === null || $d->visibility === 'organisation' || $d->owner_id === $viewer->id) {
                        $impact['dashboards'][] = ['id' => $d->id, 'title' => $d->title];
                    } else {
                        $impact['hidden']++;
                    }
                }
            }
        }

        return $impact;
    }

    /**
     * Columns of the dataset that a semantic model reads.
     *
     * @return array<string, true>
     */
    private function usedColumns(Dataset $dataset): array
    {
        $used = [];
        foreach (SemanticModel::with(['measures', 'dimensions', 'relationships'])->get() as $model) {
            if ($model->base_dataset_id === $dataset->id) {
                foreach ($model->measures as $m) {
                    if ($m->field) {
                        $used[$m->field] = true;
                    }
                    foreach ($m->filters ?? [] as $f) {
                        $used[$f['field']] = true;
                    }
                }
            }
            foreach ($model->dimensions as $d) {
                if ($d->dataset_id === $dataset->id) {
                    $used[$d->field] = true;
                }
            }
            foreach ($model->relationships as $r) {
                if ($r->from_dataset_id === $dataset->id) {
                    $used[$r->from_field] = true;
                }
                if ($r->to_dataset_id === $dataset->id) {
                    $used[$r->to_field] = true;
                }
            }
        }

        return $used;
    }

    /**
     * A breaking change to a column in use is critical; to an unused column it is a warning.
     *
     * @param  Change  $c
     * @param  array<string, true>  $used
     * @param  array<string, mixed>  $before
     */
    private function severity(array $c, array $used, array $before): string
    {
        if (! in_array($c['kind'], self::BREAKING, true)) {
            return $c['severity'];
        }
        $column = $c['kind'] === 'column_renamed' ? ($c['before']['name'] ?? $c['column']) : $c['column'];

        return isset($used[$column]) ? 'critical' : 'warning';
    }

    /** @param  list<DriftEvent>  $events */
    private function notify(Dataset $dataset, array $events): void
    {
        $serious = array_filter($events, fn (DriftEvent $e) => $e->severity !== 'info');
        if ($serious === []) {
            return;
        }
        $critical = count(array_filter($serious, fn (DriftEvent $e) => $e->severity === 'critical'));
        $impact = $this->impact($dataset, null, null);
        $owners = SemanticModel::with('metrics:id,semantic_model_id,key,data_owner_id,business_owner_id')->where('base_dataset_id', $dataset->id)->get()
            ->flatMap(fn ($m) => $m->metrics->flatMap(fn ($x) => [$x->data_owner_id, $x->business_owner_id]))->filter()->unique()->values()->all();
        $first = array_values($serious)[0];
        $payload = [
            'type' => 'data_quality', 'severity' => $critical ? 'critical' : 'warning',
            'title' => "{$dataset->label}: ".count($serious).' schema '.(count($serious) === 1 ? 'change' : 'changes').' detected',
            'body' => $first->message.($impact['metrics'] ? ' It may affect '.count($impact['metrics']).' metric(s).' : ''),
            'link' => "/trust?dataset={$dataset->id}", 'data' => ['dataset_id' => $dataset->id],
        ];
        // Metric owners and the data team, each told once.
        $team = User::with('roles')->where('organisation_id', $dataset->organisation_id)->where('status', 'active')->get()
            ->filter(fn (User $u) => $u->hasPermission('data.manage'))->pluck('id')->all();
        $this->notifier->toUsers(array_values(array_unique([...$owners, ...$team])), $dataset->organisation_id, $payload);
    }
}
