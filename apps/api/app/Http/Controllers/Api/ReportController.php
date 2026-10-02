<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Domain\Query\TimeRange;
use App\Domain\Reports\ReportBuilder;
use App\Domain\Reports\ReportExportService;
use App\Domain\Reports\ReportVersioning;
use App\Http\Controllers\Controller;
use App\Jobs\ExportReportJob;
use App\Models\Report;
use App\Models\ReportExport;
use App\Models\ReportTemplate;
use App\Models\ScheduledReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public const SECTION_TYPES = 'summary,kpis,chart,breakdown,anomalies,root_cause,forecast,risks,text';

    public function __construct(private readonly AuditLogger $audit, private readonly ReportVersioning $versions) {}

    public function templates(): JsonResponse
    {
        return response()->json(['data' => ReportTemplate::where(fn ($q) => $q->whereNull('organisation_id')->orWhere('organisation_id', request()->user()->organisation_id))
            ->orderBy('name')->get()]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => 'required|regex:/^[a-z_]+$/', 'name' => 'required|string', 'audience' => 'required|string',
            'description' => 'nullable|string', 'sections' => 'required|array|min:1', 'sections.*.type' => 'required|in:'.self::SECTION_TYPES,
            'sections.*.title' => 'required|string', 'theme' => 'nullable|string']);
        $tpl = ReportTemplate::updateOrCreate(['organisation_id' => $request->user()->organisation_id, 'key' => $data['key']], $data);

        return response()->json(['data' => $tpl], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $q = $this->visible($request)->with('owner:id,name')->withCount('sections')->latest('updated_at');
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return response()->json(['data' => $q->limit(200)->get()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $report = $this->visible($request)->with(['sections', 'owner:id,name', 'versions' => fn ($q) => $q->select('id', 'report_id', 'version', 'status', 'note', 'created_by', 'created_at')])->findOrFail($id);

        return response()->json(['data' => $report->toArray() + [
            'exports' => ReportExport::where('report_id', $id)->latest()->limit(10)->get(),
            'schedules' => ScheduledReport::where('report_id', $id)->get(),
            'can_edit' => $this->canEdit($request, $report),
        ]]);
    }

    /** Generate from a template (the AI Report Agent calls this after choosing one). */
    public function generate(Request $request, ReportBuilder $builder): JsonResponse
    {
        $data = $request->validate([
            'template' => 'required_without:sections|string', 'sections' => 'array', 'sections.*.type' => 'required|in:'.self::SECTION_TYPES, 'sections.*.title' => 'required|string',
            'title' => 'nullable|string|max:200', 'subtitle' => 'nullable|string|max:200', 'range' => 'nullable', 'theme' => 'nullable|string',
            'type' => 'nullable|string', 'focus_metrics' => 'array', 'exclude_sections' => 'array',
        ]);
        $template = isset($data['template'])
            ? ReportTemplate::where('key', $data['template'])->where(fn ($q) => $q->whereNull('organisation_id')->orWhere('organisation_id', $request->user()->organisation_id))->orderByDesc('organisation_id')->firstOrFail()
            : null;
        $blueprints = $data['sections'] ?? $template->sections;
        if (! empty($data['exclude_sections'])) {
            $blueprints = array_values(array_filter($blueprints, fn ($b) => ! in_array($b['type'], $data['exclude_sections'], true)));
        }
        if (! empty($data['focus_metrics'])) {
            foreach ($blueprints as &$b) {
                if ($b['type'] === 'kpis') {
                    $b['metrics'] = array_values(array_unique([...$data['focus_metrics'], ...$b['metrics']]));
                }
            }
            unset($b);
        }
        $range = $data['range'] ?? 'last_month';
        $period = TimeRange::resolve($range);

        $report = Report::create([
            'owner_id' => $request->user()->id, 'template_id' => $template?->id,
            'title' => $data['title'] ?? ($template?->name ?? 'Management Report').' — '.$period->label,
            'subtitle' => $data['subtitle'] ?? $template?->description, 'type' => $data['type'] ?? $template?->audience ?? 'management',
            'theme' => $data['theme'] ?? $template?->theme ?? 'executive', 'status' => 'draft',
            'parameters' => ['range' => $range, 'range_label' => $period->label, 'period' => $period->toArray()],
        ]);
        $builder->build($report, $blueprints, $request->user());
        $this->versions->snapshot($report, $request->user(), 'Generated');
        $this->audit->record('report.generated', ['resource_type' => 'report', 'resource_id' => $report->id], ['template' => $template?->key]);

        return response()->json(['data' => $report->fresh('sections')], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => 'required|string|max:200', 'subtitle' => 'nullable|string|max:200', 'type' => 'nullable|string', 'theme' => 'nullable|string', 'range' => 'nullable']);
        $period = TimeRange::resolve($data['range'] ?? 'last_month');
        $report = Report::create(['owner_id' => $request->user()->id, 'title' => $data['title'], 'subtitle' => $data['subtitle'] ?? null,
            'type' => $data['type'] ?? 'management', 'theme' => $data['theme'] ?? 'executive',
            'parameters' => ['range' => $data['range'] ?? 'last_month', 'range_label' => $period->label, 'period' => $period->toArray()]]);

        return response()->json(['data' => $report], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $report = $this->editable($request, $id);
        $report->update($request->validate(['title' => 'sometimes|string|max:200', 'subtitle' => 'sometimes|nullable|string|max:200', 'theme' => 'sometimes|string', 'type' => 'sometimes|string']));

        return response()->json(['data' => $report]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->editable($request, $id)->delete();
        $this->audit->record('report.deleted', ['resource_type' => 'report', 'resource_id' => $id]);

        return response()->json(null, 204);
    }

    public function addSection(Request $request, string $id, ReportBuilder $builder): JsonResponse
    {
        $report = $this->editable($request, $id);
        $data = $request->validate(['type' => 'required|in:'.self::SECTION_TYPES, 'title' => 'required|string', 'position' => 'nullable|integer', 'blueprint' => 'array']);
        $position = $data['position'] ?? ($report->sections()->max('position') + 1);
        $report->sections()->where('position', '>=', $position)->increment('position');
        $section = $report->sections()->create(['type' => $data['type'], 'title' => $data['title'], 'position' => $position,
            'content' => ['blueprint' => ['type' => $data['type'], 'title' => $data['title']] + ($data['blueprint'] ?? [])]]);
        if (! in_array($data['type'], ['summary', 'risks'], true)) {
            $builder->rebuildSection($section, $request->user());
        }

        return response()->json(['data' => $section->fresh()], 201);
    }

    public function updateSection(Request $request, string $id, string $sectionId, ReportBuilder $builder): JsonResponse
    {
        $report = $this->editable($request, $id);
        $section = $report->sections()->findOrFail($sectionId);
        $data = $request->validate(['title' => 'sometimes|string', 'blueprint' => 'sometimes|array', 'content' => 'sometimes|array', 'rebuild' => 'boolean']);
        if (isset($data['title'])) {
            $section->title = $data['title'];
        }
        if (isset($data['content'])) {
            // Only narrative fields are user-editable; numbers always come from queries.
            $section->content = array_merge($section->content, array_intersect_key($data['content'], array_flip(['paragraphs', 'markdown', 'caption', 'note'])));
        }
        if (isset($data['blueprint'])) {
            $section->content = array_merge($section->content, ['blueprint' => array_merge($section->content['blueprint'] ?? [], $data['blueprint'])]);
        }
        $section->save();
        if (($data['rebuild'] ?? false) || isset($data['blueprint'])) {
            $builder->rebuildSection($section, $request->user());
        }

        return response()->json(['data' => $section->fresh()]);
    }

    public function deleteSection(Request $request, string $id, string $sectionId): JsonResponse
    {
        $this->editable($request, $id)->sections()->where('id', $sectionId)->delete();

        return response()->json(null, 204);
    }

    public function reorder(Request $request, string $id): JsonResponse
    {
        $report = $this->editable($request, $id);
        $order = $request->validate(['order' => 'required|array', 'order.*' => 'uuid'])['order'];
        DB::transaction(fn () => collect($order)->each(fn ($sid, $i) => $report->sections()->where('id', $sid)->update(['position' => $i])));

        return response()->json(['data' => $report->fresh('sections')->sections]);
    }

    public function refresh(Request $request, string $id, ReportBuilder $builder): JsonResponse
    {
        $report = $this->editable($request, $id);
        $builder->build($report, $report->sections->map(fn ($s) => $s->content['blueprint'] ?? ['type' => $s->type, 'title' => $s->title])->all(), $request->user());

        return response()->json(['data' => $report->fresh('sections')]);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $report = $this->editable($request, $id);
        abort_unless($request->user()->hasPermission('reports.publish'), 403, 'Publishing requires the reports.publish permission.');
        $report->update(['status' => 'published', 'published_at' => now()]);
        $version = $this->versions->snapshot($report, $request->user(), $request->input('note', 'Published'));
        $this->audit->record('report.published', ['resource_type' => 'report', 'resource_id' => $id], ['version' => $version->version]);

        return response()->json(['data' => $report->fresh(), 'version' => $version->version]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $report = $this->editable($request, $id);
        $report->update(['status' => 'archived']);
        $this->audit->record('report.archived', ['resource_type' => 'report', 'resource_id' => $id]);

        return response()->json(['data' => $report]);
    }

    public function saveVersion(Request $request, string $id): JsonResponse
    {
        $version = $this->versions->snapshot($this->editable($request, $id), $request->user(), $request->input('note'));

        return response()->json(['data' => $version], 201);
    }

    public function restore(Request $request, string $id, int $version): JsonResponse
    {
        $report = $this->versions->restore($this->editable($request, $id), $version, $request->user());
        $this->audit->record('report.restored', ['resource_type' => 'report', 'resource_id' => $id], ['version' => $version]);

        return response()->json(['data' => $report]);
    }

    public function compare(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['a' => 'required|integer', 'b' => 'required|integer']);

        return response()->json(['data' => $this->versions->compare($this->visible($request)->findOrFail($id), $data['a'], $data['b'])]);
    }

    public function duplicate(Request $request, string $id): JsonResponse
    {
        $source = $this->visible($request)->with('sections')->findOrFail($id);
        $copy = DB::transaction(function () use ($source, $request) {
            $copy = $source->replicate(['current_version', 'published_at', 'status']);
            $copy->fill(['title' => $source->title.' (copy)', 'owner_id' => $request->user()->id, 'status' => 'draft', 'current_version' => 0])->save();
            foreach ($source->sections as $s) {
                $copy->sections()->create($s->only(['position', 'type', 'title', 'content']));
            }

            return $copy;
        });

        return response()->json(['data' => $copy->load('sections')], 201);
    }

    public function export(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['format' => 'required|in:'.implode(',', ReportExportService::FORMATS)]);
        $report = $this->visible($request)->findOrFail($id);
        $export = ReportExport::create(['report_id' => $report->id, 'format' => $data['format'], 'status' => 'queued', 'requested_by' => $request->user()->id]);
        ExportReportJob::dispatch($export->id, notify: config('queue.default') !== 'sync');
        $this->audit->record('report.export', ['resource_type' => 'report', 'resource_id' => $id], ['format' => $data['format']]);

        return response()->json(['data' => $export->fresh()], 202);
    }

    public function exportStatus(string $exportId): JsonResponse
    {
        return response()->json(['data' => ReportExport::findOrFail($exportId)]);
    }

    public function download(Request $request, string $exportId, ReportExportService $service): StreamedResponse|JsonResponse
    {
        $export = ReportExport::findOrFail($exportId);
        $report = $this->visible($request)->findOrFail($export->report_id);
        if ($export->status !== 'ready') {
            return response()->json(['error' => ['code' => 'not_ready', 'message' => 'This export is still being prepared.']], 409);
        }
        $this->audit->record('report.download', ['resource_type' => 'report_export', 'resource_id' => $exportId]);
        $name = str($report->title)->slug().'.'.$service->exporter($export->format)->extension();

        return Storage::disk('local')->download($export->path, $name, ['Content-Type' => $service->exporter($export->format)->mime()]);
    }

    public function schedule(Request $request, string $id): JsonResponse
    {
        $report = $this->editable($request, $id);
        $data = $request->validate(['frequency' => 'required|in:daily,weekly,monthly,quarterly', 'time_of_day' => 'nullable|date_format:H:i',
            'formats' => 'array', 'formats.*' => 'in:'.implode(',', ReportExportService::FORMATS), 'channels' => 'array', 'channels.*' => 'in:email,push,in_app',
            'recipients' => 'array', 'recipients.*' => 'uuid']);
        $schedule = ScheduledReport::create($data + ['report_id' => $report->id, 'created_by' => $request->user()->id,
            'next_run_at' => \App\Domain\Reports\ScheduleCalculator::next($data['frequency'], $data['time_of_day'] ?? '08:00')]);

        return response()->json(['data' => $schedule], 201);
    }

    public function unschedule(Request $request, string $id, string $scheduleId): JsonResponse
    {
        $this->editable($request, $id);
        ScheduledReport::where('report_id', $id)->where('id', $scheduleId)->delete();

        return response()->json(null, 204);
    }

    /** Drafts are private to their owner; publishers (editorial role) see every report. */
    private function visible(Request $request): Builder
    {
        $user = $request->user();

        return Report::query()->when(! $user->hasPermission('reports.publish'), fn ($q) => $q->where(
            fn ($q) => $q->where('owner_id', $user->id)->orWhereIn('status', ['published', 'archived'])
        ));
    }

    private function editable(Request $request, string $id): Report
    {
        $report = $this->visible($request)->with('sections')->findOrFail($id);
        abort_unless($this->canEdit($request, $report), 403, 'You can view this report but not edit it.');

        return $report;
    }

    private function canEdit(Request $request, Report $r): bool
    {
        $u = $request->user();

        return $u->hasPermission('reports.manage') && ($r->owner_id === $u->id || $u->hasPermission('reports.publish'));
    }
}
