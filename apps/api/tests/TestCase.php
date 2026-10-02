<?php

namespace Tests;

use App\Domain\Identity\JwtService;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function user(string $email): User
    {
        return TenantScopeBypass::run(fn () => User::with('roles')->where('email', $email)->firstOrFail());
    }

    /** Authenticates subsequent requests as the given demo user. */
    protected function as(string $email): static
    {
        $user = $this->user($email);
        app(TenantContext::class)->clear();

        return $this->withHeader('Authorization', 'Bearer '.app(JwtService::class)->accessToken($user));
    }

    /** Sets tenant context for direct service calls. */
    protected function actAs(string $email): User
    {
        $user = $this->user($email);
        app(TenantContext::class)->set($user);

        return $user;
    }
}
