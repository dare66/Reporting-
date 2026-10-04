<?php

namespace App\Domain\Data;

use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\IngestionRun;
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

    /**
     * @param  array<int, array<string, mixed>>  $rows  records keyed by column name
     * @param  'full'|'append'  $mode
     * @param  string|null  $label  display name; defaults to the name
     * @return array{run: IngestionRun, dataset: Dataset}
     */
    public function ingest(DataSource $source, string $name, array $rows, string $mode = 'full', ?string $label = null): array
    {
        $run = IngestionRun::create(['organisation_id' => $source->organisation_id, 'data_source_id' => $source->id, 'mode' => $mode, 'status' => 'running', 'started_at' => now()]);
        $started = microtime(true);
        $log = [];
        $warnings = 0;

        try {
            if ($rows === []) {
                throw new InvalidArgumentException('The source returned no records.');
            }
            $columns = $this->inferColumns($rows, $log, $warnings);
            $table = $this->tableName($source->organisation_id, $name);

            DB::transaction(function () use ($table, $columns, $rows, $mode) {
                $q = fn ($id) => '"'.$id.'"';
                $exists = DB::selectOne('SELECT to_regclass(?) AS t', ["analytics.{$table}"])->t !== null;
                if ($mode === 'full' || ! $exists) {
                    DB::statement("DROP TABLE IF EXISTS analytics.{$q($table)}");
                    $defs = implode(', ', array_map(fn ($c, $t) => $q($c).' '.$t, array_keys($columns), $columns));
                    DB::statement("CREATE TABLE analytics.{$q($table)} ({$defs})");
                    DB::statement("GRANT SELECT ON analytics.{$q($table)} TO aixbi_reader");
                }
                foreach (array_chunk($rows, self::BATCH) as $chunk) {
                    DB::table("analytics.{$table}")->insert(array_map(fn ($r) => $this->coerce($r, $columns), $chunk));
                }
            });

            $dataset = $this->registrar->register($source, 'analytics', $table, $label ?? Str::headline($name));
            $source->update(['status' => 'connected', 'last_sync_at' => now(), 'last_error' => null]);
            $run->update(['status' => 'succeeded', 'records' => count($rows), 'warning_count' => $warnings, 'log' => $log,
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
            $out[$col] = ($v === '' || $v === null) ? null : match ($columns[$col]) {
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

    private function tableName(string $orgId, string $name): string
    {
        return 'ds_'.substr(str_replace('-', '', $orgId), 0, 8).'_'.substr($this->sanitize($name), 0, 40);
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
