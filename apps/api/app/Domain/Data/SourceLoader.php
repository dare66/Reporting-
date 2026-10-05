<?php

namespace App\Domain\Data;

use App\Domain\Audit\AuditLogger;
use App\Domain\Notifications\Notifier;
use App\Models\DataSource;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Loads a database source's tables into the analytical store, one after
 * another, recording progress on the source so the screen can follow it. A
 * table that fails is recorded and skipped; the others still load. Reloading
 * updates the same datasets, so drift between loads is detected.
 *
 * @phpstan-type TableProgress array{table: string, status: string, rows: int|null, dataset_id: string|null, error: string|null}
 * @phpstan-type Load array{status: string, row_limit: int, tables: list<TableProgress>, requested_by: string|null, started_at: string, finished_at: string|null}
 */
class SourceLoader
{
    public const MAX_TABLES = 50;

    public const DEFAULT_ROW_LIMIT = 200000;

    public function __construct(
        private readonly Connectors $connectors,
        private readonly TabularIngestor $ingestor,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Records the requested load as queued; the job then runs it.
     *
     * @param  list<string>|null  $tables  null = every table and view
     * @return Load
     */
    public function queue(DataSource $source, ?array $tables, int $rowLimit, User $by): array
    {
        $available = array_column($this->connectors->tables($source), 'name');
        $tables ??= $available;
        if ($tables === []) {
            throw new InvalidArgumentException('This database has no tables to load.');
        }
        if (count($tables) > self::MAX_TABLES) {
            throw new InvalidArgumentException('Choose at most '.self::MAX_TABLES.' tables at a time.');
        }
        if ($unknown = array_diff($tables, $available)) {
            throw new InvalidArgumentException('Not found in this database: '.implode(', ', $unknown).'.');
        }
        $load = [
            'status' => 'queued', 'row_limit' => $rowLimit, 'requested_by' => $by->id, 'started_at' => now()->toIso8601String(), 'finished_at' => null,
            'tables' => array_map(fn ($t) => ['table' => $t, 'status' => 'queued', 'rows' => null, 'dataset_id' => null, 'error' => null], $tables),
        ];
        $source->update(['load_progress' => $load]);

        return $load;
    }

    public function run(DataSource $source): void
    {
        $load = $source->load_progress;
        if (! is_array($load) || ($load['status'] ?? null) === 'done') {
            return;
        }
        $load['status'] = 'running';
        $source->update(['load_progress' => $load]);

        foreach ($load['tables'] as $i => $t) {
            $load['tables'][$i]['status'] = 'loading';
            $source->update(['load_progress' => $load]);
            try {
                $result = $this->ingestor->ingest($source, $this->datasetName($source, $t['table']),
                    $this->connectors->stream($source, $t['table'], (int) $load['row_limit']), 'full', Str::headline($t['table']));
                $load['tables'][$i] = ['table' => $t['table'], 'status' => 'loaded', 'rows' => (int) $result['run']->records, 'dataset_id' => $result['dataset']->id, 'error' => null];
            } catch (Throwable $e) {
                $load['tables'][$i] = ['table' => $t['table'], 'status' => 'failed', 'rows' => null, 'dataset_id' => null,
                    'error' => Str::limit(preg_replace('/password=\S+/', 'password=***', $e->getMessage()) ?? 'Failed.', 300)];
            }
            $source->update(['load_progress' => $load]);
        }

        $loaded = count(array_filter($load['tables'], fn ($t) => $t['status'] === 'loaded'));
        $load['status'] = 'done';
        $load['finished_at'] = now()->toIso8601String();
        // A table that failed is reported on its own row; the source stays usable for the ones that loaded.
        $source->update(['load_progress' => $load, 'status' => $loaded ? 'connected' : 'error', 'last_error' => $loaded ? null : 'No table could be loaded.']);
        $this->audit->record('data_source.loaded', ['resource_type' => 'data_source', 'resource_id' => $source->id],
            ['tables' => count($load['tables']), 'loaded' => $loaded], $load['requested_by'], $source->organisation_id);

        if ($load['requested_by']) {
            $failed = count($load['tables']) - $loaded;
            $this->notifier->toUsers([$load['requested_by']], $source->organisation_id, [
                'type' => 'data_quality', 'severity' => $failed ? 'warning' : 'info',
                'title' => "{$source->name}: {$loaded} of ".count($load['tables']).' tables loaded',
                'body' => $loaded ? 'Auto BI is ready to design the dashboard and report.'.($failed ? " {$failed} table(s) could not be loaded." : '') : 'No table could be loaded.',
                'link' => $loaded ? "/data/sources/{$source->id}/auto-bi" : '/data', 'data' => ['data_source_id' => $source->id],
            ]);
        }
    }

    /** One dataset per source table, stable across reloads so drift is measured against the previous load. */
    private function datasetName(DataSource $source, string $table): string
    {
        return Str::limit(Str::snake(Str::ascii($source->name)), 20, '').'_'.$table;
    }
}
