<?php

namespace App\Domain\Data;

use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\IngestionRun;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Loads tabular records into the analytical store as a typed table and
 * registers it as a dataset. Shared by every connector (files, databases,
 * APIs, webhooks): connectors only produce rows.
 */
class TabularIngestor
{
    private const BATCH = 1000;

    public function __construct(private readonly DatasetRegistrar $registrar) {}

    /** Records sampled to infer column types. */
    private const SAMPLE = 2000;

    /** @var array<string, array{0: int, 1: string}> values that did not fit their column's type in the current load: column → [count, type] */
    private array $mismatches = [];

    /**
     * @param  iterable<array<string, mixed>>  $rows  records keyed by column name; a generator is read once, in batches
     * @param  'full'|'append'  $mode
     * @param  string|null  $label  display name; defaults to the name
     * @return array{run: IngestionRun, dataset: Dataset}
     */
    public function ingest(DataSource $source, string $name, iterable $rows, string $mode = 'full', ?string $label = null): array
    {
        $this->mismatches = [];
        $run = IngestionRun::create(['organisation_id' => $source->organisation_id, 'data_source_id' => $source->id, 'mode' => $mode, 'status' => 'running', 'started_at' => now()]);
        $started = microtime(true);
        $log = [];
        $warnings = 0;

        try {
            // Types are inferred from a sample; the rest of the rows stream through in batches.
            $iterator = (fn () => yield from $rows)();
            $sample = [];
            while ($iterator->valid() && count($sample) < self::SAMPLE) {
                $sample[] = $iterator->current();
                $iterator->next();
            }
            if ($sample === []) {
                throw new InvalidArgumentException('The source returned no records.');
            }
            $columns = $this->inferColumns($sample, $log, $warnings);
            $table = $this->tableName($source, $name);
            $count = 0;

            DB::transaction(function () use ($table, $columns, $sample, $iterator, $mode, &$count) {
                $q = fn ($id) => '"'.$id.'"';
                $exists = DB::selectOne('SELECT to_regclass(?) AS t', ["analytics.{$table}"])->t !== null;
                if ($mode === 'full' || ! $exists) {
                    DB::statement("DROP TABLE IF EXISTS analytics.{$q($table)}");
                    $defs = implode(', ', array_map(fn ($c, $t) => $q($c).' '.$t, array_keys($columns), $columns));
                    DB::statement("CREATE TABLE analytics.{$q($table)} ({$defs})");
                    DB::statement("GRANT SELECT ON analytics.{$q($table)} TO aixbi_reader");
                }
                $insert = function (array $chunk) use ($table, $columns, &$count) {
                    DB::table("analytics.{$table}")->insert(array_map(fn ($r) => $this->coerce($r, $columns), $chunk));
                    $count += count($chunk);
                };
                foreach (array_chunk($sample, self::BATCH) as $chunk) {
                    $insert($chunk);
                }
                $chunk = [];
                for (; $iterator->valid(); $iterator->next()) {
                    $chunk[] = $iterator->current();
                    if (count($chunk) === self::BATCH) {
                        $insert($chunk);
                        $chunk = [];
                    }
                }
                if ($chunk !== []) {
                    $insert($chunk);
                }
            });
            foreach ($this->mismatches as $col => [$n, $type]) {
                $warnings++;
                $log[] = ['level' => 'warning', 'message' => "{$n} value(s) in {$col} did not fit its type ({$type}) and were left empty rather than changed."];
            }

            $dataset = $this->registrar->register($source, 'analytics', $table, $label ?? Str::headline($name));
            $source->update(['status' => 'connected', 'last_sync_at' => now(), 'last_error' => null]);
            $run->update(['status' => 'succeeded', 'records' => $count, 'warning_count' => $warnings, 'log' => $log,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'finished_at' => now()]);

            return ['run' => $run->fresh(), 'dataset' => $dataset];
        } catch (Throwable $e) {
            $source->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            $run->update(['status' => 'failed', 'error_count' => 1, 'log' => [...$log, ['level' => 'error', 'message' => $e->getMessage()]],
                'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'finished_at' => now()]);
            throw $e;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<array{level: string, message: string}>  $log  appended to
     * @return array<string, string> sanitized column => SQL type
     */
    private function inferColumns(array $rows, array &$log, int &$warnings): array
    {
        $sample = array_slice($rows, 0, 2000);
        $names = [];
        foreach ($sample as $r) {
            foreach (array_keys($r) as $k) {
                $names[$k] = true;
            }
        }
        $columns = [];
        foreach (array_keys($names) as $raw) {
            $col = $this->sanitize((string) $raw);
            $values = array_values(array_filter(array_column($sample, $raw), fn ($v) => $v !== null && $v !== ''));
            $type = match (true) {
                $values === [] => 'text',
                $this->all($values, fn ($v) => is_bool($v) || in_array(strtolower((string) $v), ['true', 'false'], true)) => 'boolean',
                $this->all($values, fn ($v) => is_int($v) || preg_match('/^-?\d{1,15}$/', (string) $v)) => 'bigint',
                $this->all($values, fn ($v) => is_numeric($v)) => 'double precision',
                $this->all($values, fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) => 'date',
                $this->all($values, fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', (string) $v)) => 'timestamp',
                default => 'text',
            };
            if (count($values) < count($sample) * 0.5) {
                $warnings++;
                $log[] = ['level' => 'warning', 'message' => "Column {$col} is more than 50% empty."];
            }
            $columns[$col] = $type;
        }
        $log[] = ['level' => 'info', 'message' => 'Inferred '.count($columns).' columns from '.count($sample).' sampled records.'];

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $columns  sanitized column => SQL type
     * @return array<string, mixed>
     */
    private function coerce(array $row, array $columns): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $col = $this->sanitize((string) $k);
            if (! isset($columns[$col])) {
                continue;
            }
            if ($v === '' || $v === null) {
                $out[$col] = null;

                continue;
            }
            $type = $columns[$col];
            // A value that does not fit the inferred type is left empty, never silently turned into 0 or a wrong date.
            $fits = match ($type) {
                'boolean' => is_bool($v) || in_array(strtolower((string) $v), ['true', 'false', '1', '0'], true),
                'bigint' => is_int($v) || (bool) preg_match('/^-?\d{1,18}$/', (string) $v),
                'double precision' => is_numeric($v),
                'date' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v),
                'timestamp' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', (string) $v),
                default => true,
            };
            if (! $fits) {
                $this->mismatches[$col] = [($this->mismatches[$col][0] ?? 0) + 1, $type];
                $out[$col] = null;

                continue;
            }
            $out[$col] = match ($type) {
                'boolean' => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
                'bigint' => (int) $v,
                'double precision' => (float) $v,
                default => is_scalar($v) ? (string) $v : json_encode($v),
            };
        }

        return $out;
    }

    private function sanitize(string $name): string
    {
        $s = Str::of($name)->ascii()->snake()->replaceMatches('/[^a-z0-9_]/', '_')->replaceMatches('/_+/', '_')->trim('_')->value();
        if ($s === '' || ctype_digit($s[0])) {
            $s = 'c_'.$s;
        }

        return substr($s, 0, 60);
    }

    /**
     * One analytical table per organisation, project and dataset name. The default project keeps the
     * original naming, so tables loaded before projects existed are still found and reloaded in place.
     */
    private function tableName(DataSource $source, string $name): string
    {
        $project = Project::withoutGlobalScopes()->find($source->project_id);
        $scope = $project === null || $project->is_default ? '' : 'p'.substr(str_replace('-', '', strrev($project->id)), 0, 6).'_';

        return 'ds_'.substr(str_replace('-', '', $source->organisation_id), 0, 8).'_'.$scope.substr($this->sanitize($name), 0, 40);
    }

    /** @param  array<mixed>  $values */
    private function all(array $values, callable $fn): bool
    {
        foreach ($values as $v) {
            if (! $fn($v)) {
                return false;
            }
        }

        return true;
    }
}
