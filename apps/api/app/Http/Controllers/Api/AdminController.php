<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\FeatureFlag;
use App\Models\IngestionRun;
use App\Models\Permission;
use App\Models\ReportExport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class AdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function users(Request $request): JsonResponse
    {
        $q = User::with('roles', 'department', 'team')->orderBy('name');
        if ($s = $request->query('q')) {
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%"));
        }

        return response()->json(['data' => UserResource::collection($q->get())]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:160', 'email' => 'required|email|unique:users,email', 'title' => 'nullable|string|max:160',
            'password' => 'required|string|min:12', 'roles' => 'required|array|min:1', 'roles.*' => 'string', 'attributes' => 'array',
            'department_id' => 'nullable|uuid', 'team_id' => 'nullable|uuid',
        ]);
        $roles = $this->roleIds($data['roles'], $request);
        $user = User::create(Arr::except($data, ['roles']) + ['email' => strtolower($data['email'])]);
        $user->roles()->sync($roles);
        $this->audit->record('admin.user_created', ['resource_type' => 'user', 'resource_id' => $user->id], ['roles' => $data['roles']]);

        return response()->json(['data' => new UserResource($user->load('roles'))], 201);
    }

    public function updateUser(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:160', 'title' => 'sometimes|nullable|string|max:160', 'status' => 'sometimes|in:active,suspended',
            'roles' => 'sometimes|array|min:1', 'attributes' => 'sometimes|array', 'department_id' => 'sometimes|nullable|uuid', 'team_id' => 'sometimes|nullable|uuid',
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
        ]);
        abort_if($user->id === $request->user()->id && ($data['status'] ?? 'active') !== 'active', 422, 'You cannot suspend your own account.');
        $user->update(Arr::except($data, ['roles']));
        if (isset($data['roles'])) {
            $user->roles()->sync($this->roleIds($data['roles'], $request));
        }
        if (($data['status'] ?? null) === 'suspended') {
            DB::table('refresh_tokens')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
        $this->audit->record('admin.user_updated', ['resource_type' => 'user', 'resource_id' => $id], ['fields' => array_keys($data)]);

        return response()->json(['data' => new UserResource($user->fresh()->load('roles'))]);
    }

    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::visible()->with('permissions:id,key')->withCount('permissions')->orderBy('is_system', 'desc')->orderBy('name')->get()]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => 'required|regex:/^[a-z_]+$/', 'name' => 'required|string', 'description' => 'nullable|string',
            'experience' => 'required|in:executive,analyst,engineer,admin', 'permissions' => 'required|array', 'permissions.*' => 'exists:permissions,key']);
        abort_if(in_array('*', $data['permissions'], true), 422, 'Custom roles cannot hold the wildcard permission.');
        $role = Role::create(Arr::except($data, ['permissions']) + ['organisation_id' => $request->user()->organisation_id, 'is_system' => false]);
        $role->permissions()->sync(Permission::whereIn('key', $data['permissions'])->pluck('id'));
        $this->audit->record('admin.role_created', ['resource_type' => 'role', 'resource_id' => $role->id], ['permissions' => $data['permissions']]);

        return response()->json(['data' => $role->load('permissions')], 201);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => Permission::where('key', '!=', '*')->orderBy('group')->orderBy('key')->get()]);
    }

    public function organisation(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->organisation]);
    }

    public function updateOrganisation(Request $request): JsonResponse
    {
        $org = $request->user()->organisation;
        $org->update($request->validate(['name' => 'sometimes|string|max:160', 'currency' => 'sometimes|string|size:3', 'timezone' => 'sometimes|timezone',
            'branding' => 'sometimes|array', 'branding.accent' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/', 'settings' => 'sometimes|array']));
        $this->audit->record('admin.org_updated', ['resource_type' => 'organisation', 'resource_id' => $org->id]);

        return response()->json(['data' => $org]);
    }

    public function flags(Request $request): JsonResponse
    {
        $org = $request->user()->organisation_id;
        $flags = FeatureFlag::whereNull('organisation_id')->orWhere('organisation_id', $org)->get()->groupBy('key')
            ->map(fn ($g) => ($g->firstWhere('organisation_id', $org) ?? $g->first())->only(['key', 'enabled', 'description', 'organisation_id']))->values();

        return response()->json(['data' => $flags]);
    }

    public function setFlag(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $flag = FeatureFlag::updateOrCreate(['organisation_id' => $request->user()->organisation_id, 'key' => $key], $data);
        $this->audit->record('admin.flag', ['resource_type' => 'feature_flag', 'resource_id' => $key], $data);

        return response()->json(['data' => $flag]);
    }

    /** Live component health. Every probe is real; unconfigured components say so. */
    public function health(): JsonResponse
    {
        $probe = function (callable $fn) {
            $t = hrtime(true);
            try {
                $detail = $fn();

                return ['status' => 'operational', 'latency_ms' => round((hrtime(true) - $t) / 1e6, 1), 'detail' => $detail];
            } catch (Throwable $e) {
                return ['status' => 'down', 'latency_ms' => round((hrtime(true) - $t) / 1e6, 1), 'detail' => substr($e->getMessage(), 0, 200)];
            }
        };
        $hourAgo = now()->subHour();
        $queries = AuditLog::where('action', 'query.run')->where('created_at', '>=', $hourAgo);

        $components = [
            'api' => ['status' => 'operational', 'latency_ms' => 0, 'detail' => 'PHP '.PHP_VERSION.' · Laravel '.app()->version()],
            'database' => $probe(fn () => 'PostgreSQL '.DB::selectOne('SHOW server_version')->server_version),
            'analytics_store' => $probe(fn () => DB::connection('analytics')->selectOne('SELECT count(*) AS n FROM information_schema.tables WHERE table_schema = ?', ['analytics'])->n.' analytical tables (read-only role)'),
            'redis' => $probe(function () {
                Cache::store('redis')->put('health:ping', 1, 10);

                return 'cache round-trip ok';
            }),
            'ai_service' => $probe(function () {
                $r = Http::timeout(3)->get(config('aixbi.ai.url').'/health');
                throw_if($r->failed(), new RuntimeException('HTTP '.$r->status()));

                return 'planner: '.($r->json('planner') ?? 'unknown');
            }),
            'report_workers' => $probe(function () {
                $failed = ReportExport::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count();
                $queued = ReportExport::whereIn('status', ['queued', 'running'])->where('created_at', '<', now()->subMinutes(10))->count();
                throw_if($queued > 0, new RuntimeException("{$queued} exports waiting over 10 minutes"));

                return 'queue '.config('queue.default').", {$failed} failures in 24h";
            }),
            'data_pipelines' => $probe(function () {
                $err = DataSource::where('status', 'error')->count();
                throw_if($err > 0, new RuntimeException("{$err} data sources failing"));

                return IngestionRun::where('started_at', '>=', now()->subDay())->count().' runs in 24h';
            }),
            'storage' => $probe(function () {
                Storage::disk('local')->put('health.txt', (string) time());

                return 'writable';
            }),
            'kafka' => config('aixbi.streaming.kafka_brokers')
                ? ['status' => 'unknown', 'latency_ms' => null, 'detail' => 'Brokers configured; the streaming consumer is not deployed yet']
                : ['status' => 'not_configured', 'latency_ms' => null, 'detail' => 'No brokers configured for this environment'],
        ];

        return response()->json(['data' => [
            'components' => $components,
            'query_performance' => [
                'last_hour_queries' => (clone $queries)->count(),
                'avg_ms' => round((float) (clone $queries)->avg('duration_ms'), 1),
                'p95_ms' => (float) ((clone $queries)->selectRaw('percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_ms) AS p')->value('p') ?? 0),
                'cache_hit_rate' => ($n = (clone $queries)->count()) ? round((clone $queries)->where('meta->cached', true)->count() / $n, 3) : null,
                'failures' => (clone $queries)->where('result', 'failure')->count(),
            ],
            'checked_at' => now()->toIso8601String(),
        ]]);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function roleIds(array $keys, Request $request): array
    {
        $roles = Role::visible()->whereIn('key', $keys)->get();
        abort_if($roles->count() !== count(array_unique($keys)), 422, 'One or more roles do not exist.');
        abort_if($roles->contains('key', 'super_admin') && ! $request->user()->roles->contains('key', 'super_admin'), 403, 'Only super admins can grant super admin.');

        return $roles->pluck('id')->all();
    }
}
