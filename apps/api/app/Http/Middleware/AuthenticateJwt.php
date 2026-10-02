<?php

namespace App\Http\Middleware;

use App\Domain\Identity\JwtService;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScopeBypass;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateJwt
{
    public function __construct(private readonly JwtService $jwt, private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $this->queryToken($request);
        if (! $token) {
            return $this->unauthorised('Authentication required.');
        }

        try {
            $claims = $this->jwt->decode($token);
        } catch (Throwable) {
            return $this->unauthorised('Your session has expired. Please sign in again.');
        }

        /** @var User|null $user */
        $user = TenantScopeBypass::run(fn () => User::with('roles')->find($claims['sub']));
        if (! $user || $user->status !== 'active' || $user->organisation_id !== ($claims['org'] ?? null)) {
            return $this->unauthorised('This account is not active.');
        }

        $this->tenant->set($user);
        Auth::setUser($user);

        return $next($request);
    }

    /** EventSource cannot set headers, so SSE endpoints accept ?access_token=. */
    private function queryToken(Request $request): ?string
    {
        return $request->isMethod('GET') && str_ends_with($request->path(), '/stream')
            ? $request->query('access_token')
            : null;
    }

    private function unauthorised(string $message): Response
    {
        return response()->json(['error' => ['code' => 'unauthenticated', 'message' => $message]], 401);
    }
}
