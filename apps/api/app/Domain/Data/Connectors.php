<?php

namespace App\Domain\Data;

use App\Models\DataSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PDO;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
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
    /** @return list<Record> */
    public function readFile(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        return match ($ext) {
            'csv', 'txt' => $this->csv($file->getRealPath()),
            'json' => $this->json(file_get_contents($file->getRealPath()), null),
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

    /** @return list<Record> */
    public function pull(DataSource $source, ?string $table = null, int $limit = 200000): array
    {
        return match ($source->connector_key) {
            'postgresql', 'mysql', 'mariadb' => $this->pullTable($source, $table ?? throw new InvalidArgumentException('Choose a table to ingest.'), $limit),
            'rest_api' => $this->pullApi($source),
            default => throw new InvalidArgumentException('This source is loaded by upload or webhook.'),
        };
    }

    private function pdo(DataSource $source): PDO
    {
        $c = $source->config ?? [];
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
            $schema = $source->config['schema'] ?? ($source->connector_key === 'postgresql' ? 'public' : $source->config['database']);
            $stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name LIMIT 500');
            $stmt->execute([$schema]);

            return ['ok' => true, 'message' => 'Connected successfully.', 'tables' => $stmt->fetchAll(PDO::FETCH_COLUMN)];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Connection failed: '.preg_replace('/password=\S+/', 'password=***', $e->getMessage())];
        }
    }

    /** @return list<Record> */
    private function pullTable(DataSource $source, string $table, int $limit): array
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException('Invalid table name.');
        }
        $pdo = $this->pdo($source);
        $schema = $source->config['schema'] ?? null;
        $q = $source->connector_key === 'postgresql' ? '"' : '`';
        $ref = ($schema ? $q.$schema.$q.'.' : '').$q.$table.$q;

        return $pdo->query("SELECT * FROM {$ref} LIMIT ".(int) $limit)->fetchAll();
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
        $req = Http::timeout(30)->acceptJson();
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
        $fh = fopen($path, 'r');
        $first = fgets($fh);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($fh);
        $header = fgetcsv($fh, 0, $delimiter);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // BOM
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delimiter)) !== false) {
            if ($r === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($r, 0, count($header)), count($header), null));
        }
        fclose($fh);

        return $rows;
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
     *
     * @return list<Record>
     */
    private function excel(IReader $reader, string $path): array
    {
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $rows = [];
        foreach ($reader->load($path)->getActiveSheet()->getRowIterator() as $row) {
            $cells = $row->getCellIterator();
            $cells->setIterateOnlyExistingCells(false);
            $rows[] = array_map(
                fn ($cell) => $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue(),
                iterator_to_array($cells, false),
            );
        }
        $header = array_map('strval', array_shift($rows) ?? []);
        $width = count($header);
        $rows = array_filter($rows, fn ($r) => array_filter($r, fn ($v) => $v !== null && $v !== '') !== []);

        return array_values(array_map(
            fn ($r) => array_combine($header, array_pad(array_slice($r, 0, $width), $width, null)),
            $rows,
        ));
    }
}
