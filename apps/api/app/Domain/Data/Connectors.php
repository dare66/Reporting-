<?php

namespace App\Domain\Data;

use App\Models\DataSource;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PDO;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Connector drivers: each turns a source into rows. Adding a connector means
 * adding a method here (or a class) and a catalogue row — nothing else changes.
 *
 * @phpstan-type Record array<string, mixed>
 * @phpstan-type ConnectionTest array{ok: bool, message: string, tables?: list<string>}
 */
class Connectors
{
    public function __construct(private readonly OutboundGuard $guard = new OutboundGuard) {}

    /** @return list<Record> records of the file (the first sheet with data, for a workbook) */
    public function readFile(UploadedFile $file): array
    {
        return array_values($this->readSheets($file))[0] ?? [];
    }

    /**
     * Every table in a file: one per non-empty sheet for a workbook, one for CSV/JSON.
     *
     * @return array<string, list<Record>> sheet title → records
     */
    public function readSheets(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        return match ($ext) {
            'csv', 'txt', 'tsv' => ['Sheet1' => $this->csv($file->getRealPath())],
            'json' => ['Sheet1' => $this->json(file_get_contents($file->getRealPath()), null)],
            'xlsx' => $this->excel(new Xlsx, $file->getRealPath()),
            'xls' => $this->excel(new Xls, $file->getRealPath()),
            default => throw new InvalidArgumentException("Unsupported file type .{$ext}. Use CSV, Excel or JSON."),
        };
    }

    /** @return ConnectionTest */
    public function test(DataSource $source): array
    {
        return match ($source->connector_key) {
            'postgresql', 'mysql', 'mariadb' => $this->testDatabase($source),
            'rest_api' => $this->testApi($source),
            'csv', 'excel', 'json', 'webhook' => ['ok' => true, 'message' => 'No connection required.'],
            default => ['ok' => false, 'message' => 'This connector is on the roadmap and not yet available.'],
        };
    }

    public const DATABASES = ['postgresql', 'mysql', 'mariadb'];

    /** @return list<Record> */
    public function pull(DataSource $source, ?string $table = null, int $limit = 200000): array
    {
        return match ($source->connector_key) {
            'postgresql', 'mysql', 'mariadb' => iterator_to_array($this->stream($source, $table ?? throw new InvalidArgumentException('Choose a table to ingest.'), $limit), false),
            'rest_api' => $this->pullApi($source),
            default => throw new InvalidArgumentException('This source is loaded by upload or webhook.'),
        };
    }

    private function pdo(DataSource $source): PDO
    {
        $c = $source->config ?? [];
        $this->guard->assertHostAllowed((string) ($c['host'] ?? ''));
        $driver = $source->connector_key === 'postgresql' ? 'pgsql' : 'mysql';
        $port = $c['port'] ?? ($driver === 'pgsql' ? 5432 : 3306);
        $pdo = new PDO("{$driver}:host={$c['host']};port={$port};dbname={$c['database']}", $c['username'] ?? null, $c['password'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Read-only session where the engine supports it.
        $pdo->exec($driver === 'pgsql' ? 'SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY' : 'SET SESSION TRANSACTION READ ONLY');

        return $pdo;
    }

    /** @return ConnectionTest */
    private function testDatabase(DataSource $source): array
    {
        try {
            $pdo = $this->pdo($source);
            $stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name LIMIT 500');
            $stmt->execute([$this->schema($source)]);

            return ['ok' => true, 'message' => 'Connected successfully.', 'tables' => $stmt->fetchAll(PDO::FETCH_COLUMN)];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Connection failed: '.preg_replace('/password=\S+/', 'password=***', $e->getMessage())];
        }
    }

    /**
     * Tables and views of a database source, with the engine's own row estimate.
     *
     * @return list<array{name: string, type: string, rows: int|null}>
     */
    public function tables(DataSource $source): array
    {
        if (! in_array($source->connector_key, self::DATABASES, true)) {
            throw new InvalidArgumentException('Only database sources have tables to discover.');
        }
        $pdo = $this->pdo($source);
        $schema = $this->schema($source);
        $sql = $source->connector_key === 'postgresql'
            ? 'SELECT t.table_name AS name, t.table_type AS type, GREATEST(c.reltuples, -1)::bigint AS rows
               FROM information_schema.tables t
               LEFT JOIN pg_class c ON c.relname = t.table_name AND c.relnamespace = to_regnamespace(t.table_schema)::oid
               WHERE t.table_schema = ? ORDER BY t.table_name LIMIT 500'
            : 'SELECT table_name AS name, table_type AS type, table_rows AS `rows` FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$schema]);

        return array_map(fn ($r) => [
            'name' => (string) $r['name'],
            'type' => str_contains(strtoupper((string) $r['type']), 'VIEW') ? 'view' : 'table',
            'rows' => $r['rows'] === null || (int) $r['rows'] < 0 ? null : (int) $r['rows'],
        ], $stmt->fetchAll());
    }

    /**
     * Rows of one table, read one at a time so large tables never sit in memory whole.
     *
     * @return Generator<int, Record>
     */
    public function stream(DataSource $source, string $table, int $limit): Generator
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException('Invalid table name.');
        }
        $pdo = $this->pdo($source);
        if ($source->connector_key !== 'postgresql') {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        $q = $source->connector_key === 'postgresql' ? '"' : '`';
        $ref = $q.$this->schema($source).$q.'.'.$q.$table.$q;
        // When the limit cuts a table short, the most recent rows are the ones worth reporting on.
        $dated = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?
            AND data_type IN ('date', 'datetime', 'timestamp', 'timestamp without time zone', 'timestamp with time zone') ORDER BY ordinal_position LIMIT 1");
        $dated->execute([$this->schema($source), $table]);
        $latest = $dated->fetchColumn();
        $order = is_string($latest) ? ' ORDER BY '.$q.$latest.$q.' DESC'.($source->connector_key === 'postgresql' ? ' NULLS LAST' : '') : '';
        $stmt = $pdo->query("SELECT * FROM {$ref}{$order} LIMIT ".max(1, $limit));
        while (($row = $stmt->fetch()) !== false) {
            yield $row;
        }
    }

    private function schema(DataSource $source): string
    {
        $schema = (string) ($source->config['schema'] ?? ($source->connector_key === 'postgresql' ? 'public' : $source->config['database'] ?? ''));
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $schema)) {
            throw new InvalidArgumentException('Invalid schema name.');
        }

        return $schema;
    }

    /** @return ConnectionTest */
    private function testApi(DataSource $source): array
    {
        try {
            $count = count($this->pullApi($source));

            return ['ok' => true, 'message' => "Reached the API and found {$count} records."];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return list<Record> */
    private function pullApi(DataSource $source): array
    {
        $c = $source->config ?? [];
        $this->guard->assertUrlAllowed((string) ($c['url'] ?? ''));
        // Redirects are not followed: a redirect could lead to an address the guard refuses.
        $req = Http::timeout(30)->acceptJson()->withoutRedirecting();
        if (! empty($c['auth_header'])) {
            $req = $req->withHeaders(['Authorization' => $c['auth_header']]);
        }
        $res = $req->get($c['url']);
        if ($res->failed()) {
            throw new InvalidArgumentException("The API responded with HTTP {$res->status()}.");
        }

        return $this->json($res->body(), $c['records_path'] ?? null);
    }

    /** @return list<Record> */
    private function csv(string $path): array
    {
        $text = $this->utf8((string) file_get_contents($path));
        if (trim($text) === '') {
            return [];
        }
        $delimiter = $this->delimiter($text);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            $rows[] = $r;
        }
        fclose($fh);

        return $this->table($rows);
    }

    /**
     * Text as UTF-8, whatever the program that wrote it used: UTF-8 (with or
     * without a byte-order mark), UTF-16 (Excel's "Unicode text") or Windows-1252
     * (Excel's "CSV" on Windows).
     */
    private function utf8(string $text): string
    {
        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($text, 2), 'UTF-8', str_starts_with($text, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);

        return mb_check_encoding($text, 'UTF-8') ? $text : (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    /** The separator that splits the first lines into the same, largest number of columns: comma, semicolon, tab or pipe. */
    private function delimiter(string $text): string
    {
        $lines = array_slice(array_values(array_filter(preg_split('/\r\n|\n|\r/', substr($text, 0, 65536)) ?: [], fn ($l) => trim($l) !== '')), 0, 20);
        $best = [',', 0];
        foreach ([',', ';', "\t", '|'] as $d) {
            $counts = array_map(fn ($l) => count(str_getcsv($l, $d, '"', '')), $lines);
            // The most common width among the lines; title lines above the table do not decide it.
            $widths = array_count_values($counts);
            arsort($widths);
            $width = (int) array_key_first($widths);
            if ($width > 1 && $width * $widths[$width] > $best[1]) {
                $best = [$d, $width * $widths[$width]];
            }
        }

        return $best[0];
    }

    /**
     * Turns rows of cells into records the way a person reads a sheet: the
     * header is the first row that spans the table (title and notes rows above
     * it are skipped), blank headings become "Column N", repeated headings get
     * a number, and empty rows and repeated header rows are dropped.
     *
     * @param  list<list<mixed>>  $rows
     * @return list<Record>
     */
    private function table(array $rows): array
    {
        $filled = fn (array $r) => count(array_filter($r, fn ($v) => $v !== null && trim((string) $v) !== ''));
        $rows = array_values(array_filter($rows, fn ($r) => $filled($r) > 0));
        if ($rows === []) {
            return [];
        }
        $widths = array_map($filled, array_slice($rows, 0, 200));
        $width = max($widths);
        $at = 0;
        foreach (array_slice($rows, 0, 20) as $i => $r) {
            $text = array_filter($r, fn ($v) => $v !== null && trim((string) $v) !== '' && ! is_numeric($v));
            if ($filled($r) >= max(1, (int) ceil($width * 0.6)) && count($text) >= $filled($r) / 2) {
                $at = $i;
                break;
            }
        }
        $header = [];
        $seen = [];
        foreach ($rows[$at] as $i => $h) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $h));
            $name = $name === '' ? 'Column '.($i + 1) : $name;
            $key = mb_strtolower($name);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $header[$i] = $seen[$key] > 1 ? "{$name} {$seen[$key]}" : $name;
        }
        $records = [];
        $headerValues = array_map(fn ($v) => trim((string) $v), $rows[$at]);
        foreach (array_slice($rows, $at + 1) as $r) {
            if (array_map(fn ($v) => trim((string) $v), array_slice($r, 0, count($headerValues))) === $headerValues) {
                continue; // the header repeated, e.g. at a page break
            }
            // Total and subtotal rows would be counted twice once the data is summed.
            $first = array_values(array_filter($r, fn ($v) => $v !== null && trim((string) $v) !== ''))[0] ?? null;
            if (is_string($first) && preg_match('/^(grand\s+|sub\s*-?\s*)?(total|totals|sum|jumlah)\b/i', trim($first))) {
                continue;
            }
            $record = [];
            foreach ($header as $i => $name) {
                $v = $r[$i] ?? null;
                $record[$name] = is_string($v) ? trim($v) : $v;
            }
            $records[] = $record;
        }
        // Columns with neither a heading nor any value are noise from formatting, not data.
        foreach ($header as $i => $name) {
            if (str_starts_with($name, 'Column ') && trim((string) ($rows[$at][$i] ?? '')) === ''
                && ! array_filter($records, fn ($rec) => $rec[$name] !== null && $rec[$name] !== '')) {
                foreach ($records as &$rec) {
                    unset($rec[$name]);
                }
                unset($rec);
            }
        }

        return $records;
    }

    /** @return list<Record> */
    private function json(string $body, ?string $path): array
    {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        foreach (array_filter(explode('.', (string) $path)) as $seg) {
            $data = $data[$seg] ?? throw new InvalidArgumentException("records_path segment '{$seg}' not found.");
        }
        if (! array_is_list($data)) {
            $data = $data['data'] ?? $data['records'] ?? $data['items'] ?? [$data];
        }

        return array_map(fn ($r) => is_array($r) ? array_filter($r, fn ($v) => ! is_array($v)) : ['value' => $r], $data);
    }

    /**
     * Uploaded workbooks are untrusted: the reader is chosen from the validated
     * extension (never sniffed), only cell values are read, and formulas are
     * not recalculated, so external references and WEBSERVICE() never execute.
     * Number formats are kept so date cells arrive as dates, not Excel serials.
     *
     * @return array<string, list<Record>> sheet title → records, empty sheets left out
     */
    private function excel(IReader $reader, string $path): array
    {
        $reader->setReadDataOnly(false);
        $reader->setReadEmptyCells(false);
        $sheets = [];
        foreach ($reader->load($path)->getWorksheetIterator() as $sheet) {
            $records = $this->sheetRecords($sheet);
            if ($records !== []) {
                $sheets[$sheet->getTitle()] = $records;
            }
        }

        return $sheets;
    }

    /** @return list<Record> */
    private function sheetRecords(Worksheet $sheet): array
    {
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->getCellIterator();
            $cells->setIterateOnlyExistingCells(false);
            // Read each value while its cell is current: the sheet detaches cell objects once the iterator moves on.
            $values = [];
            foreach ($cells as $cell) {
                $values[] = $this->cellValue($cell);
            }
            $rows[] = $values;
        }

        return $this->table($rows);
    }

    private function cellValue(Cell $cell): mixed
    {
        $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
        if (is_numeric($value) && ExcelDate::isDateTime($cell, $value)) {
            $date = ExcelDate::excelToDateTimeObject((float) $value);

            return floor((float) $value) == $value ? $date->format('Y-m-d') : $date->format('Y-m-d H:i:s');
        }

        return $value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText ? $value->getPlainText() : $value;
    }
}
