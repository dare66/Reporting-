<?php

namespace App\Http\Middleware;

use App\Domain\Identity\SecurityPolicy;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an account at the door until it complies with the organisation's
 * security policy: a password reset by an administrator must be replaced, and
 * MFA must be enrolled where the policy requires it. Only the routes needed
 * to comply (profile, password, MFA enrolment, sign-out) stay open.
 */
class EnforceSecurityPolicy
{
    /** Route patterns (method + path under api/v1) that remain available while the account is held. */
    private const OPEN = [
        'GET api/v1/me',
        'POST api/v1/me/password',
        'POST api/v1/me/mfa/setup',
        'POST api/v1/me/mfa/enable',
        'POST api/v1/auth/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        if (in_array($request->method().' '.$request->path(), self::OPEN, true)) {
            return $next($request);
        }
        if ($user->must_change_password) {
            return $this->held('password_change_required', 'Choose a new password to continue: an administrator reset yours.');
        }
        if (! $user->mfa_enabled && SecurityPolicy::for($user->organisation)->mfaRequiredFor($user)) {
            return $this->held('mfa_enrolment_required', 'Your organisation requires two-factor authentication. Set it up to continue.');
        }

        return $next($request);
    }

    private function held(string $code, string $message): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], 403);
    }
}
