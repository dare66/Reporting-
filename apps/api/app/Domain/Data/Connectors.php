<?php

namespace App\Domain\Data;

use App\Models\DataSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Connector drivers: each turns a source into rows. Adding a connector means
 * adding a method here (or a class) and a catalogue row — nothing else changes.
 */
class Connectors
{
    /** @return array<int, array<string, mixed>> */
    public function readFile(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        return match ($ext) {
            'csv', 'txt' => $this->csv($file->getRealPath()),
            'json' => $this->json(file_get_contents($file->getRealPath()), null),
            'xlsx', 'xls' => $this->excel($file->getRealPath()),
            default => throw new InvalidArgumentException("Unsupported file type .{$ext}. Use CSV, Excel or JSON."),
        };
    }

    /** @return array{ok: bool, message: string, tables?: array<string>} */
    public function test(DataSource $source): array
    {
        return match ($source->connector_key) {
            'postgresql', 'mysql', 'mariadb' => $this->testDatabase($source),
            'rest_api' => $this->testApi($source),
            'csv', 'excel', 'json', 'webhook' => ['ok' => true, 'message' => 'No connection required.'],
            default => ['ok' => false, 'message' => 'This connector is on the roadmap and not yet available.'],
        };
    }

    /** @return array<int, array<string, mixed>> */
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

    private function testDatabase(DataSource $source): array
    {
        try {
            $pdo = $this->pdo($source);
            $schema = $source->config['schema'] ?? ($source->connector_key === 'postgresql' ? 'public' : $source->config['database']);
            $stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name LIMIT 500');
            $stmt->execute([$schema]);

            return ['ok' => true, 'message' => 'Connected successfully.', 'tables' => $stmt->fetchAll(PDO::FETCH_COLUMN)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection failed: '.preg_replace('/password=\S+/', 'password=***', $e->getMessage())];
        }
    }

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

    private function testApi(DataSource $source): array
    {
        try {
            $count = count($this->pullApi($source));

            return ['ok' => true, 'message' => "Reached the API and found {$count} records."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

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

    private function excel(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        $header = array_shift($sheet);

        return array_map(fn ($r) => array_combine($header, $r), array_filter($sheet, fn ($r) => array_filter($r, fn ($v) => $v !== null)));
    }
}
