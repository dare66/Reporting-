<?php

namespace App\Http\Controllers\Api;

use App\Domain\AutoBi\AutoBiDesigner;
use App\Http\Controllers\Controller;
use App\Models\DataSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Auto BI Designer: propose (read-only) and publish (writes what was approved). */
class AutoBiController extends Controller
{
    /** Publishing creates a semantic model, a dashboard and a report, so it needs the right to manage each. */
    private const PUBLISH_PERMISSIONS = ['data.manage', 'semantic.manage', 'dashboards.manage', 'reports.manage'];

    public function __construct(private readonly AutoBiDesigner $designer) {}

    public function plan(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['audience' => ['nullable', Rule::in(AutoBiDesigner::AUDIENCES)], 'kpis' => 'sometimes|array|max:10', 'kpis.*' => 'string|max:80',
            'labels' => 'sometimes|array', 'labels.*' => 'nullable|string|max:80']);

        return response()->json(['data' => $this->designer->plan(DataSource::findOrFail($id), $data['audience'] ?? 'executive', isset($data['kpis']) ? array_values($data['kpis']) : null, $data['labels'] ?? [])]);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        foreach (self::PUBLISH_PERMISSIONS as $permission) {
            abort_unless($request->user()->hasPermission($permission), 403, 'Publishing needs permission to manage data, semantic models, dashboards and reports.');
        }
        $data = $request->validate([
            'kpis' => 'required|array|min:1|max:10', 'kpis.*' => 'required|string|distinct|max:80',
            'audience' => ['required', Rule::in(AutoBiDesigner::AUDIENCES)],
            'labels' => 'sometimes|array', 'labels.*' => 'nullable|string|max:80',
        ]);

        return response()->json(['data' => $this->designer->publish(DataSource::findOrFail($id), $data['kpis'], $data['audience'], $request->user(), $data['labels'] ?? [])], 201);
    }
}
