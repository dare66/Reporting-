<?php

namespace App\Domain\Data;

use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\IngestionRun;
use App\Models\Project;
use DateTimeImmutable;
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

    /** @var array<string, string> source column name → table column, unique even when two names clean up the same */
    private array $columnOf = [];

    /** @var array<string, string> table column → how its text is read: number, percent, or date:/timestamp: with a format */
    private array $readers = [];

    /** Day-first formats come before month-first ones (Malaysian convention); month-first wins only when day-first cannot read every value. */
    private const DATE_FORMATS = [
        'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y', 'd.m.Y', 'Y/m/d', 'd/m/y', 'm/d/y', 'Y.m.d',
        'd-M-Y', 'd-M-y', 'd M Y', 'j M Y', 'd F Y', 'j F Y', 'M j, Y', 'F j, Y', 'M j Y', 'D, d M Y',
    ];

    private const TIME_FORMATS = [' H:i', ' H:i:s', ' g:i A', ' g:i a', ' h:i A', ' H:i:s.u', 'TH:i:s'];

    /**
     * @param  iterable<array<string, mixed>>  $rows  records keyed by column name; a generator is read once, in batches
     * @param  'full'|'append'  $mode
     * @param  string|null  $label  display name; defaults to the name
     * @return array{run: IngestionRun, dataset: Dataset}
     */
    public function ingest(DataSource $source, string $name, iterable $rows, string $mode = 'full', ?string $label = null): array
    {
        $this->mismatches = [];
        $this->columnOf = [];
        $this->readers = [];
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
            $col = $base = $this->sanitize((string) $raw);
            for ($n = 2; isset($columns[$col]); $n++) {
                $col = substr($base, 0, 56).'_'.$n;
            }
            $this->columnOf[(string) $raw] = $col;
            $values = array_values(array_filter(array_column($sample, $raw), fn ($v) => $v !== null && $v !== ''));
            $type = match (true) {
                $values === [] => 'text',
                $this->all($values, fn ($v) => is_bool($v) || in_array(strtolower((string) $v), ['true', 'false'], true)) => 'boolean',
                $this->all($values, fn ($v) => is_int($v) || preg_match('/^-?\d{1,15}$/', (string) $v)) => 'bigint',
                $this->all($values, fn ($v) => is_numeric($v)) => 'double precision',
                $this->all($values, fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) => 'date',
                $this->all($values, fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', (string) $v)) => 'timestamp',
                default => $this->formatted($col, $values, $log),
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
            $col = $this->columnOf[(string) $k] ?? $this->sanitize((string) $k);
            if (! isset($columns[$col])) {
                continue;
            }
            if ($v === '' || $v === null) {
                $out[$col] = null;

                continue;
            }
            if (isset($this->readers[$col]) && is_string($v)) {
                $v = $this->read($this->readers[$col], $v) ?? $v;
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

    /**
     * Text columns that hold formatted numbers or dates, as people type them:
     * "RM 1,234.50", "(500)", "12.5%", "05/03/2024", "5 Mar 2024 14:30". Read as
     * numbers and dates so they can be summed and trended; otherwise text.
     *
     * @param  list<mixed>  $values
     * @param  list<array{level: string, message: string}>  $log
     */
    private function formatted(string $col, array $values, array &$log): string
    {
        $strings = array_map(fn ($v) => trim((string) $v), $values);
        $percent = $this->all($strings, fn ($v) => str_ends_with($v, '%'));
        $reader = $percent ? 'percent' : 'number';
        if ($this->all($strings, fn ($v) => $this->read($reader, $v) !== null)) {
            $this->readers[$col] = $reader;
            $log[] = ['level' => 'info', 'message' => "Read {$col} as ".($percent ? 'percentages (12% is stored as 0.12)' : 'numbers').', ignoring currency signs and thousands separators.'];
            $whole = ! $percent && $this->all($strings, fn ($v) => fmod((float) $this->read('number', $v), 1.0) === 0.0 && abs((float) $this->read('number', $v)) < 1e15);

            return $whole ? 'bigint' : 'double precision';
        }
        foreach ([...self::DATE_FORMATS, ...array_merge(...array_map(fn ($d) => array_map(fn ($t) => $d.$t, self::TIME_FORMATS), self::DATE_FORMATS))] as $format) {
            $kind = strpbrk($format, 'HgGh') !== false ? 'timestamp' : 'date';
            if ($this->all($strings, fn ($v) => $this->read("{$kind}:{$format}", $v) !== null)) {
                $this->readers[$col] = "{$kind}:{$format}";
                $log[] = ['level' => 'info', 'message' => "Read {$col} as ".($kind === 'date' ? 'dates' : 'dates and times')." written {$format}."];

                return $kind;
            }
        }

        return 'text';
    }

    /** One formatted value as a plain number or ISO date, or null when it does not follow the column's format. */
    private function read(string $reader, string $v): ?string
    {
        $v = trim($v);
        if ($reader === 'number' || $reader === 'percent') {
            $negative = (bool) preg_match('/^\((.*)\)$/', $v, $m);
            $v = $negative ? $m[1] : $v;
            $v = (string) preg_replace('/^(?:RM|MYR|USD|SGD|EUR|GBP|IDR|INR|CNY|US\$|S\$|\$|€|£|¥|₹)\s*|\s*(?:RM|MYR|USD|SGD|EUR|GBP)$/iu', '', $v);
            if ($reader === 'percent') {
                if (! str_ends_with($v, '%')) {
                    return null;
                }
                $v = rtrim(substr($v, 0, -1));
            }
            if (! preg_match('/^[-+]?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?$/', $v)) {
                return null;
            }
            $n = (float) str_replace(',', '', $v) * ($negative ? -1 : 1);

            return (string) ($reader === 'percent' ? $n / 100 : $n);
        }
        [$kind, $format] = explode(':', $reader, 2);
        $d = DateTimeImmutable::createFromFormat('!'.$format, $v);
        $errors = DateTimeImmutable::getLastErrors();
        if ($d === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || (int) $d->format('Y') < 1900 || (int) $d->format('Y') > 2200) {
            return null;
        }

        return $d->format($kind === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
    }

    private function sanitize(string $name): string
    {
        // "orderDate" → order_date, "Staff ID" → staff_id, "Revenue (RM)" → revenue_rm: words split at case changes, acronyms kept whole.
        $s = Str::of($name)->ascii()->replaceMatches('/(?<=[a-z0-9])(?=[A-Z])/', '_')->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
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
