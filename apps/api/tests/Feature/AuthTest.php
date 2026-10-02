<?php

namespace Tests\Feature;

use App\Domain\Identity\Totp;
use App\Models\AuditLog;
use Tests\SeededTestCase;

class AuthTest extends SeededTestCase
{
    public function test_login_issues_tokens_and_profile(): void
    {
        $res = $this->postJson('/api/v1/auth/login', ['email' => 'ceo@northstar.demo', 'password' => 'Demo@2026!'])->assertOk();
        $res->assertJsonStructure(['access_token', 'refresh_token', 'expires_in', 'user' => ['permissions', 'experience']]);
        $this->assertSame('executive', $res->json('user.experience'));

        $this->withHeader('Authorization', 'Bearer '.$res->json('access_token'))->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.email', 'ceo@northstar.demo');
    }

    public function test_bad_password_is_rejected_and_audited(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'ceo@northstar.demo', 'password' => 'nope'])->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_credentials');
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'auth.login')->where('decision', 'deny')->exists());
    }

    public function test_requests_without_token_are_rejected(): void
    {
        $this->getJson('/api/v1/home')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer not-a-jwt')->getJson('/api/v1/home')->assertStatus(401);
    }

    public function test_refresh_rotates_and_detects_reuse(): void
    {
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'analyst@northstar.demo', 'password' => 'Demo@2026!'])->json();
        $first = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertOk()->json();
        $this->assertNotSame($login['refresh_token'], $first['refresh_token']);

        // Replaying the rotated token is treated as theft: the whole family is revoked.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertStatus(401);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertStatus(401);
    }

    public function test_mfa_enrolment_and_challenge(): void
    {
        $secret = $this->as('coo@northstar.demo')->postJson('/api/v1/me/mfa/setup')->assertOk()->json('secret');
        $this->as('coo@northstar.demo')->postJson('/api/v1/me/mfa/enable', ['code' => Totp::code($secret)])->assertOk();

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'coo@northstar.demo', 'password' => 'Demo@2026!'])->assertOk()->assertJsonPath('mfa_required', true);
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $challenge->json('mfa_token'), 'code' => '000000'])->assertStatus(401);
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $challenge->json('mfa_token'), 'code' => Totp::code($secret)])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_suspended_users_lose_access_immediately(): void
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'viewer@northstar.demo', 'password' => 'Demo@2026!'])->json('access_token');
        $viewer = $this->user('viewer@northstar.demo');
        $this->as('admin@northstar.demo')->patchJson("/api/v1/admin/users/{$viewer->id}", ['status' => 'suspended'])->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertStatus(401);
    }
}
