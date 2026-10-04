<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\SecurityPolicy;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\Department;
use App\Models\FeatureFlag;
use App\Models\IngestionRun;
use App\Models\Permission;
use App\Models\RefreshToken;
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

    /** People in the organisation, filterable by search, role, status and department. */
    public function users(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'role' => 'nullable|string', 'status' => 'nullable|in:active,suspended', 'department_id' => 'nullable|uuid']);
        $q = User::with('roles', 'department', 'team')->withCount(['refreshTokens as active_sessions' => fn ($t) => $t->whereNull('revoked_at')->where('expires_at', '>', now())])->orderBy('name');
        if ($s = $filters['q'] ?? null) {
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%")->orWhere('title', 'ilike', "%{$s}%"));
        }
        if ($role = $filters['role'] ?? null) {
            $q->whereHas('roles', fn ($r) => $r->where('key', $role));
        }
        if ($status = $filters['status'] ?? null) {
            $q->where('status', $status);
        }
        if ($department = $filters['department_id'] ?? null) {
            $q->where('department_id', $department);
        }

        return response()->json(['data' => UserResource::collection($q->get())]);
    }

    /** One person with their sessions and recent activity, for the user detail panel. */
    public function showUser(string $id): JsonResponse
    {
        $user = User::with('roles', 'department', 'team')->findOrFail($id);

        return response()->json(['data' => (new UserResource($user))->resolve() + [
            'sessions' => RefreshToken::where('user_id', $id)->whereNull('revoked_at')->where('expires_at', '>', now())->latest()
                ->get(['id', 'device', 'ip_address', 'created_at', 'last_used_at']),
            'activity' => AuditLog::where('user_id', $id)->latest('created_at')->limit(25)
                ->get(['id', 'action', 'resource_type', 'resource_id', 'decision', 'result', 'ip_address', 'created_at']),
        ]]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $policy = SecurityPolicy::for($request->user()->organisation);
        $data = $request->validate([
            'name' => 'required|string|max:160', 'email' => 'required|email|unique:users,email', 'title' => 'nullable|string|max:160',
            'password' => $policy->passwordRules(), 'roles' => 'required|array|min:1', 'roles.*' => 'string',
            ...$this->profileRules($request),
        ]);
        abort_unless($policy->allowsEmail($data['email']), 422, 'Your organisation only allows accounts on: '.implode(', ', $policy->allowedEmailDomains).'.');
        $roles = $this->roleIds($data['roles'], $request);
        // New accounts start with an administrator-chosen password, so the person chooses their own at first sign-in.
        $user = User::create(Arr::except($data, ['roles']) + ['email' => strtolower($data['email']), 'must_change_password' => true]);
        $user->roles()->sync($roles);
        $this->audit->record('admin.user_created', ['resource_type' => 'user', 'resource_id' => $user->id], ['roles' => $data['roles']]);

        return response()->json(['data' => new UserResource($user->load('roles', 'department'))], 201);
    }

    public function updateUser(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:160', 'title' => 'sometimes|nullable|string|max:160', 'status' => 'sometimes|in:active,suspended',
            'roles' => 'sometimes|array|min:1', 'roles.*' => 'string',
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            ...$this->profileRules($request, partial: true),
        ]);
        abort_if($user->id === $request->user()->id && ($data['status'] ?? 'active') !== 'active', 422, 'You cannot suspend your own account.');
        abort_if($user->id === $request->user()->id && isset($data['roles']) && ! $this->keepsAdministration($data['roles']), 422, 'You cannot remove your own administration access.');
        $user->update(Arr::except($data, ['roles']));
        if (isset($data['roles'])) {
            $user->roles()->sync($this->roleIds($data['roles'], $request));
        }
        if (($data['status'] ?? null) === 'suspended') {
            $this->revokeAll($user);
        }
        $this->audit->record('admin.user_updated', ['resource_type' => 'user', 'resource_id' => $id], ['fields' => array_keys($data)]);

        return response()->json(['data' => new UserResource($user->fresh()->load('roles', 'department', 'team'))]);
    }

    /**
     * Issues a one-time password, shown to the administrator once. The person
     * must replace it at next sign-in, and every existing session ends.
     */
    public function resetPassword(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        abort_if($user->id === $request->user()->id, 422, 'Change your own password from your settings.');
        $temporary = SecurityPolicy::temporaryPassword();
        $user->update(['password' => $temporary, 'must_change_password' => true]);
        $this->revokeAll($user);
        $this->audit->record('admin.password_reset', ['resource_type' => 'user', 'resource_id' => $id]);

        return response()->json(['data' => ['temporary_password' => $temporary]]);
    }

    /** Clears a lost authenticator: the person enrols again at next sign-in. */
    public function resetMfa(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update(['mfa_enabled' => false, 'mfa_secret' => null]);
        $this->revokeAll($user);
        $this->audit->record('admin.mfa_reset', ['resource_type' => 'user', 'resource_id' => $id]);

        return response()->json(['data' => new UserResource($user->load('roles'))]);
    }

    /** Signs a person out everywhere (e.g. a lost laptop). */
    public function revokeSessions(string $id): JsonResponse
    {
        $count = $this->revokeAll(User::findOrFail($id));
        $this->audit->record('admin.sessions_revoked', ['resource_type' => 'user', 'resource_id' => $id], ['count' => $count]);

        return response()->json(['data' => ['revoked' => $count]]);
    }

    public function departments(): JsonResponse
    {
        return response()->json(['data' => Department::withCount('users')->orderBy('name')->get(['id', 'name', 'parent_id'])]);
    }

    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::visible()->with('permissions:id,key')->withCount('permissions', 'users')
            ->orderBy('is_system', 'desc')->orderBy('name')->get()]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'regex:/^[a-z_]+$/', 'max:60', Rule::unique('roles', 'key')->where(fn ($q) => $q->whereNull('organisation_id')->orWhere('organisation_id', $request->user()->organisation_id))],
            ...$this->roleRules(),
        ]);
        $role = Role::create(Arr::except($data, ['permissions']) + ['organisation_id' => $request->user()->organisation_id, 'is_system' => false]);
        $role->permissions()->sync(Permission::whereIn('key', $data['permissions'])->pluck('id'));
        $this->audit->record('admin.role_created', ['resource_type' => 'role', 'resource_id' => $role->id], ['permissions' => $data['permissions']]);

        return response()->json(['data' => $role->load('permissions')->loadCount('users', 'permissions')], 201);
    }

    /** Edits a custom role; platform roles are fixed so every tenant shares their meaning. */
    public function updateRole(Request $request, string $id): JsonResponse
    {
        $role = $this->customRole($id);
        $data = $request->validate($this->roleRules(partial: true));
        $role->update(Arr::except($data, ['permissions']));
        if (isset($data['permissions'])) {
            $role->permissions()->sync(Permission::whereIn('key', $data['permissions'])->pluck('id'));
        }
        $this->audit->record('admin.role_updated', ['resource_type' => 'role', 'resource_id' => $id], ['fields' => array_keys($data)]);

        return response()->json(['data' => $role->load('permissions')->loadCount('users', 'permissions')]);
    }

    public function deleteRole(string $id): JsonResponse
    {
        $role = $this->customRole($id);
        abort_if($role->users()->exists(), 422, 'Move the people in this role to another role before deleting it.');
        $role->delete();
        $this->audit->record('admin.role_deleted', ['resource_type' => 'role', 'resource_id' => $id]);

        return response()->json(null, 204);
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

    public function securityPolicy(Request $request): JsonResponse
    {
        return response()->json(['data' => SecurityPolicy::for($request->user()->organisation)->toArray()]);
    }

    public function updateSecurityPolicy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password_min_length' => 'required|integer|min:'.SecurityPolicy::MIN_PASSWORD.'|max:'.SecurityPolicy::MAX_PASSWORD,
            'require_mfa' => ['required', Rule::in(SecurityPolicy::MFA_MODES)],
            'allowed_email_domains' => 'present|array|max:20',
            'allowed_email_domains.*' => ['string', 'max:120', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/i'],
        ]);
        $org = $request->user()->organisation;
        $policy = new SecurityPolicy($data['password_min_length'], $data['require_mfa'], array_values(array_unique(array_map('strtolower', $data['allowed_email_domains']))));
        // An administrator cannot lock their own session out with a policy they have not met yet.
        abort_if($policy->mfaRequiredFor($request->user()) && ! $request->user()->mfa_enabled, 422, 'Set up two-factor authentication on your own account before requiring it.');
        $org->update(['settings' => ['security' => $policy->toArray()] + (array) ($org->settings ?? [])]);
        $this->audit->record('admin.security_policy', ['resource_type' => 'organisation', 'resource_id' => $org->id], $policy->toArray());

        return response()->json(['data' => $policy->toArray()]);
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
     * Profile fields an administrator sets: department and data scope (row-level security attributes).
     *
     * @return array<string, mixed>
     */
    private function profileRules(Request $request, bool $partial = false): array
    {
        $org = $request->user()->organisation_id;
        $sometimes = $partial ? 'sometimes|' : '';

        return [
            'department_id' => [...($partial ? ['sometimes'] : []), 'nullable', 'uuid', Rule::exists('departments', 'id')->where('organisation_id', $org)],
            'team_id' => [...($partial ? ['sometimes'] : []), 'nullable', 'uuid', Rule::exists('teams', 'id')->where('organisation_id', $org)],
            'attributes' => $sometimes.'array',
            'attributes.country_codes' => 'sometimes|array|max:250',
            'attributes.country_codes.*' => 'string|regex:/^[A-Z]{2}$/',
        ];
    }

    /** @return array<string, mixed> */
    private function roleRules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => "{$req}|string|max:80", 'description' => 'sometimes|nullable|string|max:300',
            'experience' => "{$req}|in:executive,analyst,engineer,admin",
            'permissions' => "{$req}|array", 'permissions.*' => ['string', Rule::exists('permissions', 'key'), 'not_in:*'],
        ];
    }

    private function customRole(string $id): Role
    {
        $role = Role::visible()->findOrFail($id);
        abort_if($role->is_system || $role->organisation_id === null, 422, 'Platform roles cannot be changed. Create a custom role instead.');

        return $role;
    }

    /** @param  array<string>  $roleKeys */
    private function keepsAdministration(array $roleKeys): bool
    {
        return Role::visible()->whereIn('key', $roleKeys)->whereHas('permissions', fn ($p) => $p->whereIn('key', ['*', 'admin.users']))->exists();
    }

    private function revokeAll(User $user): int
    {
        return RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
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
