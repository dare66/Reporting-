<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\JwtService;
use App\Domain\Identity\SecurityPolicy;
use App\Domain\Identity\Totp;
use App\Domain\Notifications\Notifier;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\RefreshToken;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AuthController extends Controller
{
    private const DUMMY_HASH = '$2y$12$dtQv9kpbZzo0NMhCd8pvgeqW8w0mxzDds4dcyPfJIGPfv/WjeJlG2';

    public function __construct(private readonly JwtService $jwt, private readonly AuditLogger $audit, private readonly TenantContext $tenant) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = TenantScopeBypass::run(fn () => User::with('roles')->where('email', strtolower($data['email']))->first());

        // Constant work whether or not the user exists, to avoid account enumeration.
        $valid = Hash::check($data['password'], $user->password ?? self::DUMMY_HASH);
        if (! $user || ! $valid || $user->status !== 'active') {
            $this->audit->record('auth.login', ['decision' => 'deny', 'result' => 'failure'], ['email' => $data['email']], $user?->id, $user?->organisation_id);

            return response()->json(['error' => ['code' => 'invalid_credentials', 'message' => 'That email and password combination is not recognised.']], 401);
        }

        if ($user->mfa_enabled) {
            return response()->json(['mfa_required' => true, 'mfa_token' => $this->jwt->mfaChallengeToken($user)]);
        }

        return $this->completeLogin($user, $request);
    }

    public function verifyMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['mfa_token' => 'required|string', 'code' => 'required|digits:6']);
        try {
            $claims = $this->jwt->decode($data['mfa_token'], 'mfa');
        } catch (Throwable) {
            return response()->json(['error' => ['code' => 'mfa_expired', 'message' => 'The verification window expired. Please sign in again.']], 401);
        }
        $user = TenantScopeBypass::run(fn () => User::with('roles')->find($claims['sub']));
        if (! $user || ! $user->mfa_secret || ! Totp::verify($user->mfa_secret, $data['code'])) {
            $this->audit->record('auth.mfa', ['decision' => 'deny', 'result' => 'failure'], [], $user?->id, $user?->organisation_id);

            return response()->json(['error' => ['code' => 'invalid_code', 'message' => 'That code is not valid.']], 401);
        }

        return $this->completeLogin($user, $request);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => 'required|string']);
        $tokens = $this->jwt->refresh($data['refresh_token'], $request->userAgent(), $request->ip());

        return $tokens
            ? response()->json($tokens)
            : response()->json(['error' => ['code' => 'invalid_refresh', 'message' => 'Your session has ended. Please sign in again.']], 401);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($token = $request->input('refresh_token')) {
            $this->jwt->revoke($token);
        }
        $this->audit->record('auth.logout');

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles', 'department', 'team', 'organisation'));
    }

    /** The person's own name and title; roles, scope and email stay with administrators. */
    public function updateProfile(Request $request): UserResource
    {
        $user = $request->user();
        $user->update($request->validate(['name' => 'sometimes|string|min:2|max:160', 'title' => 'sometimes|nullable|string|max:160']));
        $this->audit->record('auth.profile_updated', ['resource_type' => 'user', 'resource_id' => $user->id]);

        return new UserResource($user->load('roles', 'department', 'team', 'organisation'));
    }

    /**
     * Changes the password under the organisation's policy. Every other session
     * ends; the one making the change stays signed in.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $policy = SecurityPolicy::for($user->organisation);
        $data = $request->validate(['current_password' => 'required|string', 'password' => $policy->passwordRules().'|confirmed|different:current_password']);
        if (! Hash::check($data['current_password'], $user->password)) {
            $this->audit->record('auth.password_change', ['decision' => 'deny', 'result' => 'failure']);

            return response()->json(['error' => ['code' => 'invalid_credentials', 'message' => 'Your current password is not correct.']], 422);
        }
        $user->update(['password' => $data['password'], 'must_change_password' => false, 'password_changed_at' => now()]);
        $keep = $request->input('refresh_token') ? hash('sha256', (string) $request->input('refresh_token')) : null;
        RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->when($keep, fn ($q) => $q->where('token_hash', '!=', $keep))->update(['revoked_at' => now()]);
        $this->audit->record('auth.password_changed');

        return response()->json(['data' => new UserResource($user->load('roles', 'department', 'team', 'organisation'))]);
    }

    /** Preferences with a known shape only, so stored settings stay meaningful. */
    public function updatePreferences(Request $request): UserResource
    {
        $data = $request->validate([
            'preferences' => 'required|array',
            'preferences.theme' => 'sometimes|in:dark,light,system',
            'preferences.accent' => 'sometimes|string|max:30',
            'preferences.home_dashboard' => 'sometimes|nullable|uuid',
            'preferences.date_format' => 'sometimes|in:day_month,month_day,iso',
            'preferences.default_range' => 'sometimes|in:last_7_days,last_30_days,last_90_days,this_month,this_quarter,last_12_months',
            'preferences.notifications' => 'sometimes|array',
            'preferences.notifications.*' => 'array',
            'preferences.notifications.*.email' => 'sometimes|boolean',
            'preferences.notifications.*.push' => 'sometimes|boolean',
        ]);
        // Validated data keeps only keys with rules, so unknown keys are checked on the raw input.
        $raw = (array) $request->input('preferences');
        $unknown = array_diff(array_keys($raw), ['theme', 'accent', 'home_dashboard', 'date_format', 'default_range', 'notifications']);
        abort_if($unknown !== [], 422, 'Unknown preference: '.implode(', ', $unknown).'.');
        $categories = array_diff(array_keys((array) ($raw['notifications'] ?? [])), Notifier::CATEGORIES);
        abort_if($categories !== [], 422, 'Unknown notification category: '.implode(', ', $categories).'.');

        $user = $request->user();
        $user->update(['preferences' => array_replace_recursive($user->preferences ?? [], $data['preferences'] ?? [])]);

        return new UserResource($user->load('roles', 'department', 'team', 'organisation'));
    }

    public function setupMfa(Request $request): JsonResponse
    {
        $user = $request->user();
        $secret = Totp::generateSecret();
        $user->update(['mfa_secret' => $secret, 'mfa_enabled' => false]);

        return response()->json(['secret' => $secret, 'otpauth_uri' => Totp::provisioningUri($secret, $user->email)]);
    }

    public function enableMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|digits:6']);
        $user = $request->user();
        if (! $user->mfa_secret || ! Totp::verify($user->mfa_secret, $data['code'])) {
            return response()->json(['error' => ['code' => 'invalid_code', 'message' => 'That code is not valid.']], 422);
        }
        $user->update(['mfa_enabled' => true]);
        $this->audit->record('auth.mfa_enabled');

        return response()->json(['mfa_enabled' => true]);
    }

    public function disableMfa(Request $request): JsonResponse
    {
        abort_if(SecurityPolicy::for($request->user()->organisation)->mfaRequiredFor($request->user()), 422, 'Your organisation requires two-factor authentication for your account.');
        $request->user()->update(['mfa_enabled' => false, 'mfa_secret' => null]);
        $this->audit->record('auth.mfa_disabled');

        return response()->json(['mfa_enabled' => false]);
    }

    public function sessions(Request $request): JsonResponse
    {
        return response()->json(['data' => RefreshToken::where('user_id', $request->user()->id)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->latest()
            ->get(['id', 'device', 'ip_address', 'created_at', 'last_used_at', 'expires_at'])]);
    }

    public function revokeSession(Request $request, string $id): JsonResponse
    {
        RefreshToken::where('user_id', $request->user()->id)->where('id', $id)->update(['revoked_at' => now()]);
        $this->audit->record('auth.session_revoked', ['resource_type' => 'session', 'resource_id' => $id]);

        return response()->json(['ok' => true]);
    }

    private function completeLogin(User $user, Request $request): JsonResponse
    {
        $this->tenant->set($user);
        $user->update(['last_login_at' => now()]);
        $this->audit->record('auth.login', [], ['mfa' => $user->mfa_enabled]);

        return response()->json($this->jwt->issue($user, $request->userAgent(), $request->ip()) + [
            'user' => new UserResource($user->load('roles', 'organisation')),
        ]);
    }
}
