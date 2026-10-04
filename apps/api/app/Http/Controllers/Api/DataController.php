<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Domain\Data\Connectors;
use App\Domain\Data\DatasetRegistrar;
use App\Domain\Data\SourceRemoval;
use App\Domain\Data\TabularIngestor;
use App\Domain\Semantic\SemanticModelGenerator;
use App\Domain\Semantic\SemanticModelImporter;
use App\Http\Controllers\Controller;
use App\Models\DataConnector;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\IngestionRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DataController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly Connectors $connectors, private readonly TabularIngestor $ingestor) {}

    public function connectors(): JsonResponse
    {
        return response()->json(['data' => DataConnector::orderByRaw("CASE status WHEN 'available' THEN 0 ELSE 1 END")->orderBy('name')->get()]);
    }

    public function sources(): JsonResponse
    {
        $sources = DataSource::withCount('datasets')->with(['runs' => fn ($q) => $q->limit(1)])->orderBy('name')->get();

        return response()->json(['data' => $sources->map(fn ($s) => $s->toArray() + ['last_run' => $s->runs->first(), 'config_keys' => array_keys($s->config ?? [])])]);
    }

    public function storeSource(Request $request): JsonResponse
    {
        $data = $request->validate(['connector_key' => 'required|exists:data_connectors,key', 'name' => 'required|string|max:160', 'config' => 'array',
            'sync_mode' => 'in:full,incremental,cdc', 'schedule' => 'nullable|string|max:60']);
        $connector = DataConnector::where('key', $data['connector_key'])->first();
        abort_if($connector->status !== 'available', 422, "{$connector->name} is on the roadmap and cannot be connected yet.");
        $isWebhook = $data['connector_key'] === 'webhook';
        $config = $data['config'] ?? [];
        if ($isWebhook) {
            $config['token'] = Str::random(40); // server-generated; never taken from the request
        }
        $source = DataSource::create(['config' => $config, 'created_by' => $request->user()->id, 'status' => 'pending'] + $data);
        $this->audit->record('data_source.created', ['resource_type' => 'data_source', 'resource_id' => $source->id], ['connector' => $source->connector_key]);

        // Like an API key, the webhook URL embeds a secret and is revealed only once.
        $ingest = $isWebhook ? ['url' => url("/api/v1/ingest/webhook/{$source->id}/{$source->config['token']}"), 'method' => 'POST'] : null;

        return response()->json(['data' => $source, 'ingest' => $ingest], 201);
    }

    /** What deleting a source would remove, and what (if anything) stops it. */
    public function sourceImpact(Request $request, string $id, SourceRemoval $removal): JsonResponse
    {
        return response()->json(['data' => $removal->impact(DataSource::findOrFail($id), $request->user())]);
    }

    public function deleteSource(Request $request, string $id, SourceRemoval $removal): JsonResponse
    {
        $source = DataSource::findOrFail($id);
        $impact = $removal->impact($source, $request->user());
        abort_unless($impact['can_delete'], 409, 'This source still feeds dashboards, reports, alerts or other data models. Remove or repoint them first.');
        $removed = $removal->remove($source, $request->user());
        $this->audit->record('data_source.deleted', ['resource_type' => 'data_source', 'resource_id' => $id], $removed + ['name' => $source->name]);

        return response()->json(null, 204);
    }

    public function test(string $id): JsonResponse
    {
        $source = DataSource::findOrFail($id);
        $result = $this->connectors->test($source);
        $source->update(['status' => $result['ok'] ? 'connected' : 'error', 'last_error' => $result['ok'] ? null : $result['message']]);

        return response()->json(['data' => $result]);
    }

    public function sync(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['table' => 'nullable|string', 'name' => 'nullable|string|max:60', 'mode' => 'in:full,append']);
        $source = DataSource::findOrFail($id);
        $rows = $this->connectors->pull($source, $data['table'] ?? null);
        $result = $this->ingestor->ingest($source, $data['name'] ?? $data['table'] ?? $source->name, $rows, $data['mode'] ?? 'full');
        $this->audit->record('data_source.synced', ['resource_type' => 'data_source', 'resource_id' => $id], ['records' => count($rows)]);

        return response()->json(['data' => $result]);
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => 'required|file|max:51200|mimes:csv,txt,xlsx,xls,json', 'name' => 'nullable|string|max:60']);
        $file = $data['file'];
        $ext = strtolower($file->getClientOriginalExtension());
        $connector = match ($ext) {
            'xlsx', 'xls' => 'excel', 'json' => 'json', default => 'csv'
        };
        $name = $data['name'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $source = DataSource::create(['connector_key' => $connector, 'name' => Str::headline($name).' (upload)', 'status' => 'syncing',
            'config' => ['filename' => $file->getClientOriginalName()], 'created_by' => $request->user()->id]);
        $sheets = $this->connectors->readSheets($file);
        abort_if($sheets === [], 422, 'The file contains no records.');
        // A workbook with several sheets becomes one dataset per sheet; the table name keeps the file, the label is the sheet.
        $results = [];
        foreach ($sheets as $sheet => $records) {
            $results[] = count($sheets) > 1
                ? $this->ingestor->ingest($source, "{$name} {$sheet}", $records, 'full', Str::headline($sheet))
                : $this->ingestor->ingest($source, $name, $records);
        }
        foreach ($results as $r) {
            $this->audit->record('data.uploaded', ['resource_type' => 'dataset', 'resource_id' => $r['dataset']->id], ['file' => $file->getClientOriginalName()]);
        }

        return response()->json(['data' => [
            'source' => $source->fresh(),
            'dataset' => $results[0]['dataset']->load('fields'),
            'run' => $results[0]['run'],
            'datasets' => array_map(fn ($r) => $r['dataset']->load('fields'), $results),
        ]], 201);
    }

    /** Public webhook endpoint (token-authenticated): appends JSON records. */
    public function webhook(Request $request, string $id, string $token): JsonResponse
    {
        $source = DataSource::withoutGlobalScopes()->where('connector_key', 'webhook')->findOrFail($id);
        abort_unless(hash_equals((string) ($source->config['token'] ?? ''), $token), 403, 'Invalid webhook token.');
        app(\App\Support\Tenancy\TenantContext::class)->setOrganisation($source->organisation_id);
        // A body may carry one record or a list of records.
        $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($payload) && $payload !== [], 422, 'Send a JSON object or an array of objects.');
        $records = array_is_list($payload) ? $payload : [$payload];
        $result = $this->ingestor->ingest($source, $source->config['dataset'] ?? $source->name, $records, 'append');

        return response()->json(['accepted' => count($records), 'run_id' => $result['run']->id], 202);
    }

    public function runs(string $id): JsonResponse
    {
        return response()->json(['data' => IngestionRun::where('data_source_id', $id)->latest('started_at')->limit(50)->get()]);
    }

    public function datasets(): JsonResponse
    {
        return response()->json(['data' => Dataset::with('dataSource:id,name,connector_key,status,last_sync_at')->withCount('fields')->orderBy('label')->get()]);
    }

    public function dataset(Request $request, string $id): JsonResponse
    {
        $dataset = Dataset::with('fields', 'dataSource:id,name,connector_key,status,last_sync_at')->findOrFail($id);
        if (! $request->user()->hasPermission('data.sensitive')) {
            $dataset->fields->each(fn ($f) => $f->is_sensitive ? $f->profile = ['redacted' => true] : null);
        }

        return response()->json(['data' => $dataset]);
    }

    public function profile(string $id, DatasetRegistrar $registrar): JsonResponse
    {
        return response()->json(['data' => $registrar->profile(Dataset::with('fields')->findOrFail($id))->load('fields')]);
    }

    /** Row preview; sensitive columns are masked unless the user holds data.sensitive. */
    public function preview(Request $request, string $id): JsonResponse
    {
        $dataset = Dataset::with('fields')->findOrFail($id);
        $canSensitive = $request->user()->hasPermission('data.sensitive');
        $cols = $dataset->fields->map(fn ($f) => $f->is_sensitive && ! $canSensitive ? "'•••••' AS \"{$f->name}\"" : "\"{$f->name}\"")->implode(', ');
        $rows = DB::connection('analytics')->select("SELECT {$cols} FROM \"{$dataset->physical_schema}\".\"{$dataset->physical_table}\" LIMIT 50");
        $this->audit->record('dataset.preview', ['resource_type' => 'dataset', 'resource_id' => $id, 'row_count' => count($rows)]);

        return response()->json(['columns' => $dataset->fields->pluck('name'), 'rows' => $rows, 'masked' => ! $canSensitive]);
    }

    public function proposeModel(string $id, SemanticModelGenerator $generator): JsonResponse
    {
        return response()->json(['data' => $generator->propose(Dataset::with('fields')->findOrFail($id))]);
    }

    public function createModel(Request $request, string $id, SemanticModelGenerator $generator, SemanticModelImporter $importer): JsonResponse
    {
        $proposal = $request->input('definition') ?? $generator->propose(Dataset::with('fields')->findOrFail($id));
        $model = $importer->import($request->user()->organisation_id, $proposal, $request->user());
        $this->audit->record('semantic.generated', ['resource_type' => 'semantic_model', 'resource_id' => $model->id]);

        return response()->json(['data' => ['key' => $model->key, 'id' => $model->id]], 201);
    }
}
