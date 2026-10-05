<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Support\Projects\ProjectContext;
use App\Support\Projects\Projects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Projects: separate spaces for sources, models, dashboards, reports and alerts.
 * Anyone who can connect data may start a project and becomes its owner; owners
 * and organisation administrators manage its details and members.
 */
class ProjectController extends Controller
{
    /** Tables whose rows belong to a project, with the label used when explaining why one cannot be deleted. */
    private const CONTENTS = [
        'data_sources' => 'data sources', 'datasets' => 'datasets', 'semantic_models' => 'data models',
        'dashboards' => 'dashboards', 'reports' => 'reports', 'alert_rules' => 'alerts',
    ];

    public function __construct(private readonly AuditLogger $audit, private readonly ProjectContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        Projects::defaultFor($user->organisation_id);
        $ids = $this->context->active() ? $this->context->accessible() : Projects::accessibleIds($user);
        $projects = Project::whereIn('id', $ids)->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json(['data' => $projects->map(fn (Project $p) => $this->present($p, $user))->values()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $project = $this->visible($id);
        $members = $project->members()->orderBy('name')->get(['users.id', 'users.name', 'users.email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->getRelationValue('pivot')->role]);

        return response()->json(['data' => $this->present($project, $request->user()) + ['members' => $members]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'visibility' => 'sometimes|in:organisation,members',
        ]);
        $key = $this->uniqueKey($user->organisation_id, $data['name']);
        $project = DB::transaction(function () use ($data, $key, $user) {
            $project = Project::create($data + ['key' => $key, 'visibility' => $data['visibility'] ?? 'members', 'is_default' => false, 'created_by' => $user->id]);
            $project->members()->attach($user->id, ['role' => 'owner']);

            return $project;
        });
        $this->audit->record('project.created', ['resource_type' => 'project', 'resource_id' => $project->id], ['key' => $key, 'visibility' => $project->visibility]);

        return response()->json(['data' => $this->present($project, $user)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $project = $this->managed($request, $id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'description' => 'sometimes|nullable|string|max:1000',
            'visibility' => 'sometimes|in:organisation,members',
        ]);
        // The default project holds what existed before projects; it stays open to everyone.
        abort_if($project->is_default && ($data['visibility'] ?? 'organisation') !== 'organisation', 422, 'The default project is always open to the whole organisation.');
        $project->update($data);
        $this->audit->record('project.updated', ['resource_type' => 'project', 'resource_id' => $project->id], ['fields' => array_keys($data)]);

        return response()->json(['data' => $this->present($project, $request->user())]);
    }

    /** Only an empty project can be deleted, so no dashboard, report or source is ever lost with it. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $project = $this->managed($request, $id);
        abort_if($project->is_default, 422, 'The default project cannot be deleted.');
        $contents = $this->contents($project);
        if (array_sum($contents) > 0) {
            $parts = [];
            foreach ($contents as $table => $n) {
                if ($n > 0) {
                    $parts[] = $n.' '.self::CONTENTS[$table];
                }
            }

            return response()->json(['error' => ['code' => 'project_not_empty',
                'message' => 'This project still holds '.implode(', ', $parts).'. Remove or move them first.']], 409);
        }
        $project->delete();
        $this->audit->record('project.deleted', ['resource_type' => 'project', 'resource_id' => $id], ['key' => $project->key]);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** People in the organisation who could be added: for the project's managers, who may not administer users. */
    public function people(Request $request, string $id): JsonResponse
    {
        $project = $this->managed($request, $id);
        $members = DB::table('project_members')->where('project_id', $project->id)->pluck('user_id')->all();

        return response()->json(['data' => User::where('status', 'active')->whereNotIn('id', $members)->orderBy('name')->get(['id', 'name', 'title'])]);
    }

    public function addMember(Request $request, string $id): JsonResponse
    {
        $project = $this->managed($request, $id);
        $data = $request->validate([
            'user_id' => ['required', 'uuid', Rule::exists('users', 'id')->where('organisation_id', $request->user()->organisation_id)],
            'role' => 'sometimes|in:owner,member',
        ]);
        $project->members()->syncWithoutDetaching([$data['user_id'] => ['role' => $data['role'] ?? 'member']]);
        $this->audit->record('project.member_added', ['resource_type' => 'project', 'resource_id' => $project->id], ['user_id' => $data['user_id'], 'role' => $data['role'] ?? 'member']);

        return $this->show($request, $project->id);
    }

    public function removeMember(Request $request, string $id, string $userId): JsonResponse
    {
        $project = $this->managed($request, $id);
        $owners = DB::table('project_members')->where('project_id', $project->id)->where('role', 'owner')->pluck('user_id')->all();
        abort_if($owners === [$userId], 422, 'A project needs at least one owner. Make someone else an owner first.');
        $project->members()->detach($userId);
        $this->audit->record('project.member_removed', ['resource_type' => 'project', 'resource_id' => $project->id], ['user_id' => $userId]);

        return $this->show($request, $project->id);
    }

    /** @return array<string, mixed> */
    private function present(Project $p, User $user): array
    {
        $role = DB::table('project_members')->where('project_id', $p->id)->where('user_id', $user->id)->value('role');

        return [
            'id' => $p->id, 'key' => $p->key, 'name' => $p->name, 'description' => $p->description,
            'visibility' => $p->visibility, 'is_default' => $p->is_default,
            'my_role' => $role, 'can_manage' => Projects::canManage($user, $p),
            'member_count' => DB::table('project_members')->where('project_id', $p->id)->count(),
            'counts' => $this->contents($p),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, int> rows per owned table, counted across the whole project whatever the request is narrowed to */
    private function contents(Project $p): array
    {
        $counts = [];
        foreach (array_keys(self::CONTENTS) as $table) {
            $counts[$table] = DB::table($table)->where('organisation_id', $p->organisation_id)->where('project_id', $p->id)->count();
        }

        return $counts;
    }

    private function visible(string $id): Project
    {
        $project = Project::findOrFail($id);
        abort_unless($this->context->canSee($project->id), 404);

        return $project;
    }

    private function managed(Request $request, string $id): Project
    {
        $project = $this->visible($id);
        abort_unless(Projects::canManage($request->user(), $project), 403, 'Only the project owners or an administrator can change this project.');

        return $project;
    }

    private function uniqueKey(string $organisationId, string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 30, '') ?: 'project';
        $key = $base;
        for ($i = 2; Project::where('organisation_id', $organisationId)->where('key', $key)->exists(); $i++) {
            $key = $base.'_'.$i;
        }

        return $key;
    }
}
