<?php

namespace App\Domain\Data;

use App\Domain\Metrics\MetricUsage;
use App\Models\DataLineage;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\SemanticModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Removing a data source removes everything built only on it: its datasets,
 * the analytical tables AIXBI created for them, and the semantic models (with
 * their metrics and history) based on those datasets. It refuses while any
 * dashboard, report or alert still uses those models, so nobody's work
 * disappears silently. Tables AIXBI did not create are never dropped.
 *
 * @phpstan-import-type Usage from MetricUsage
 */
class SourceRemoval
{
    /** Tables AIXBI creates on ingestion; anything else was only registered and belongs to someone else. */
    public const OWNED_TABLE = '/^ds_[a-z0-9]+_[a-z0-9_]+$/';

    public function __construct(private readonly MetricUsage $usage) {}

    /**
     * @return array{
     *     datasets: list<array{id: string, label: string, table: string, rows: int, drops_table: bool}>,
     *     models: list<array{key: string, name: string, metrics: int}>,
     *     blocking: array{dashboards: list<array{id: string, title: string}>, reports: list<array{id: string, title: string}>, alerts: list<array{id: string, title: string}>, models: list<array{key: string, name: string}>, hidden: int},
     *     can_delete: bool
     * }
     */
    public function impact(DataSource $source, User $user): array
    {
        $datasets = Dataset::withoutGlobalScope('project')->where('data_source_id', $source->id)->get();
        $models = SemanticModel::withoutGlobalScope('project')->withCount('metrics')->whereIn('base_dataset_id', $datasets->pluck('id'))->get();
        $blocking = ['dashboards' => [], 'reports' => [], 'alerts' => [], 'hidden' => 0];
        $usage = $this->usage->index($user);
        foreach ($models as $model) {
            foreach ($usage as $ref => $u) {
                if (str_starts_with($ref, $model->key.'.')) {
                    foreach (['dashboards', 'reports', 'alerts'] as $kind) {
                        foreach ($u[$kind] as $item) {
                            $blocking[$kind][$item['id']] = $item;
                        }
                    }
                    $blocking['hidden'] += $u['hidden'];
                }
            }
        }
        // Models built on other sources that join to these tables would silently lose dimensions.
        $ids = $datasets->pluck('id');
        // Counted in every project: a model elsewhere still breaks if these tables go.
        $joining = SemanticModel::withoutGlobalScope('project')->whereNotIn('base_dataset_id', $ids)
            ->where(fn ($q) => $q->whereHas('relationships', fn ($r) => $r->whereIn('to_dataset_id', $ids)->orWhereIn('from_dataset_id', $ids))
                ->orWhereHas('dimensions', fn ($d) => $d->whereIn('dataset_id', $ids)))
            ->get(['key', 'name'])->map(fn ($m) => ['key' => $m->key, 'name' => $m->name])->values()->all();
        $blocking = ['dashboards' => array_values($blocking['dashboards']), 'reports' => array_values($blocking['reports']),
            'alerts' => array_values($blocking['alerts']), 'models' => $joining, 'hidden' => $blocking['hidden']];

        return [
            'datasets' => $datasets->map(fn (Dataset $d) => ['id' => $d->id, 'label' => $d->label, 'table' => "{$d->physical_schema}.{$d->physical_table}",
                'rows' => (int) $d->row_count, 'drops_table' => $this->owns($d)])->values()->all(),
            'models' => $models->map(fn (SemanticModel $m) => ['key' => $m->key, 'name' => $m->name, 'metrics' => (int) $m->metrics_count])->values()->all(),
            'blocking' => $blocking,
            'can_delete' => $blocking['dashboards'] === [] && $blocking['reports'] === [] && $blocking['alerts'] === [] && $blocking['models'] === [] && $blocking['hidden'] === 0,
        ];
    }

    /** @return array{datasets: int, models: int, tables_dropped: int} */
    public function remove(DataSource $source, User $user): array
    {
        $impact = $this->impact($source, $user);
        if (! $impact['can_delete']) {
            throw new InvalidArgumentException('This source still feeds dashboards, reports, alerts or other data models. Remove or repoint them first.');
        }
        $datasets = Dataset::withoutGlobalScope('project')->where('data_source_id', $source->id)->get();
        $dropped = 0;
        DB::transaction(function () use ($source, $datasets, &$dropped) {
            $models = SemanticModel::withoutGlobalScope('project')->whereIn('base_dataset_id', $datasets->pluck('id'))->get();
            foreach ($models as $model) {
                DataLineage::where('to_type', 'metric')->where('to_ref', 'like', $model->key.'.%')->delete();
                $model->delete(); // dimensions, measures, metrics and versions cascade
            }
            foreach ($datasets as $dataset) {
                if ($this->owns($dataset)) {
                    DB::statement('DROP TABLE IF EXISTS "'.$dataset->physical_schema.'"."'.$dataset->physical_table.'"');
                    $dropped++;
                }
                $dataset->delete();
            }
            $source->delete();
        });

        return ['datasets' => $datasets->count(), 'models' => count($impact['models']), 'tables_dropped' => $dropped];
    }

    private function owns(Dataset $dataset): bool
    {
        return $dataset->physical_schema === 'analytics' && (bool) preg_match(self::OWNED_TABLE, $dataset->physical_table)
            && str_starts_with($dataset->physical_table, 'ds_'.substr(str_replace('-', '', $dataset->organisation_id), 0, 8).'_');
    }
}
