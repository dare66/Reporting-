<?php

namespace Tests\Feature;

use App\Domain\Identity\Totp;
use App\Domain\Notifications\Notifier;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantScopeBypass;
use Tests\SeededTestCase;

/** User and role administration, the organisation security policy and self-service account settings. */
class AdministrationTest extends SeededTestCase
{
    private const ADMIN = 'admin@emgs.demo';

    private function login(string $email, string $password): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    /** A fresh client for a token, so headers from earlier requests do not leak in. */
    private function bearer(string $token): static
    {
        $this->flushHeaders();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_people_can_be_found_by_role_status_and_text_and_inspected(): void
    {
        $analysts = $this->as(self::ADMIN)->getJson('/api/v1/admin/users?role=analyst')->assertOk()->json('data');
        $this->assertSame(['analyst@emgs.demo'], array_column($analysts, 'email'));

        $this->as(self::ADMIN)->getJson('/api/v1/admin/users?q=asia')->assertOk()->assertJsonPath('data.0.email', 'manager.asia@emgs.demo');
        $this->as(self::ADMIN)->getJson('/api/v1/admin/users?status=suspended')->assertOk()->assertJsonCount(0, 'data');

        $this->login('analyst@emgs.demo', 'Demo@2026!')->assertOk();
        $id = $analysts[0]['id'];
        $detail = $this->as(self::ADMIN)->getJson("/api/v1/admin/users/{$id}")->assertOk();
        $this->assertNotEmpty($detail->json('data.sessions'));
        $this->assertSame('auth.login', $detail->json('data.activity.0.action'));
        $this->as('analyst@emgs.demo')->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_new_accounts_follow_the_policy_and_choose_their_own_password(): void
    {
        $this->as(self::ADMIN)->putJson('/api/v1/admin/security-policy', ['password_min_length' => 16, 'require_mfa' => 'none', 'allowed_email_domains' => ['emgs.demo']])->assertOk();
        $person = ['name' => 'Ana Lim', 'email' => 'ana@emgs.demo', 'roles' => ['viewer'], 'attributes' => ['country_codes' => ['JP']]];

        $this->as(self::ADMIN)->postJson('/api/v1/admin/users', $person + ['password' => 'Short-pass-1'])->assertStatus(422);
        $this->as(self::ADMIN)->postJson('/api/v1/admin/users', array_merge($person, ['email' => 'ana@gmail.com', 'password' => 'Initial-Password-2026']))->assertStatus(422);
        $this->as(self::ADMIN)->postJson('/api/v1/admin/users', array_merge($person, ['attributes' => ['country_codes' => ['japan']], 'password' => 'Initial-Password-2026']))->assertStatus(422);
        $created = $this->as(self::ADMIN)->postJson('/api/v1/admin/users', $person + ['password' => 'Initial-Password-2026'])->assertCreated();
        $this->assertTrue($created->json('data.must_change_password'));
        $this->assertSame(['country_codes' => ['JP']], $created->json('data.data_scope'));

        // Signed in with the administrator's password, the account is held until it chooses its own.
        $login = $this->login('ana@emgs.demo', 'Initial-Password-2026')->assertOk();
        $token = $login->json('access_token');
        $this->bearer($token)->getJson('/api/v1/dashboards')->assertForbidden()->assertJsonPath('error.code', 'password_change_required');
        $this->bearer($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->bearer($token)->postJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'A-much-longer-passphrase', 'password_confirmation' => 'A-much-longer-passphrase'])->assertStatus(422);
        $this->bearer($token)->postJson('/api/v1/me/password', ['current_password' => 'Initial-Password-2026', 'password' => 'too-short-9', 'password_confirmation' => 'too-short-9'])->assertStatus(422);
        $this->bearer($token)->postJson('/api/v1/me/password', ['current_password' => 'Initial-Password-2026', 'password' => 'A-much-longer-passphrase', 'password_confirmation' => 'A-much-longer-passphrase', 'refresh_token' => $login->json('refresh_token')])
            ->assertOk()->assertJsonPath('data.must_change_password', false);
        $this->bearer($token)->getJson('/api/v1/dashboards')->assertOk();
    }

    public function test_password_reset_issues_a_one_time_password_and_ends_every_session(): void
    {
        $session = $this->login('analyst@emgs.demo', 'Demo@2026!')->json('refresh_token');
        $id = $this->user('analyst@emgs.demo')->id;

        $temporary = $this->as(self::ADMIN)->postJson("/api/v1/admin/users/{$id}/reset-password")->assertOk()->json('data.temporary_password');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{5}(-[A-Za-z0-9]{5}){3}$/', $temporary);

        $this->flushHeaders();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $session])->assertUnauthorized();
        $this->login('analyst@emgs.demo', 'Demo@2026!')->assertUnauthorized();
        $this->login('analyst@emgs.demo', $temporary)->assertOk();
        $this->assertTrue($this->user('analyst@emgs.demo')->must_change_password);

        $this->as(self::ADMIN)->postJson('/api/v1/admin/users/'.$this->user(self::ADMIN)->id.'/reset-password')->assertStatus(422);
    }

    public function test_mfa_reset_and_sign_out_everywhere(): void
    {
        $user = $this->user('analyst@emgs.demo');
        $user->update(['mfa_secret' => Totp::generateSecret(), 'mfa_enabled' => true]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Demo@2026!'])->assertJsonPath('mfa_required', true);

        $this->as(self::ADMIN)->deleteJson("/api/v1/admin/users/{$user->id}/mfa")->assertOk()->assertJsonPath('data.mfa_enabled', false);
        $this->flushHeaders();
        $this->login($user->email, 'Demo@2026!')->assertOk()->assertJsonMissingPath('mfa_required');
        $this->login($user->email, 'Demo@2026!')->assertOk();

        $this->as(self::ADMIN)->deleteJson("/api/v1/admin/users/{$user->id}/sessions")->assertOk()->assertJsonPath('data.revoked', 2);
    }

    public function test_administrators_cannot_lock_themselves_out(): void
    {
        $admin = $this->user(self::ADMIN);
        $this->as(self::ADMIN)->patchJson("/api/v1/admin/users/{$admin->id}", ['roles' => ['viewer']])->assertStatus(422);
        $this->as(self::ADMIN)->patchJson("/api/v1/admin/users/{$admin->id}", ['status' => 'suspended'])->assertStatus(422);
        $this->as(self::ADMIN)->putJson('/api/v1/admin/security-policy', ['password_min_length' => 12, 'require_mfa' => 'admins', 'allowed_email_domains' => []])
            ->assertStatus(422);
    }

    public function test_required_mfa_holds_accounts_until_they_enrol(): void
    {
        $this->user(self::ADMIN)->update(['mfa_secret' => Totp::generateSecret(), 'mfa_enabled' => true]);
        $this->as(self::ADMIN)->putJson('/api/v1/admin/security-policy', ['password_min_length' => 12, 'require_mfa' => 'all', 'allowed_email_domains' => []])->assertOk();

        $this->as('analyst@emgs.demo')->getJson('/api/v1/dashboards')->assertForbidden()->assertJsonPath('error.code', 'mfa_enrolment_required');
        $this->as('analyst@emgs.demo')->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.security.mfa_required', true);
        $secret = $this->as('analyst@emgs.demo')->postJson('/api/v1/me/mfa/setup')->assertOk()->json('secret');
        $this->as('analyst@emgs.demo')->postJson('/api/v1/me/mfa/enable', ['code' => Totp::code($secret)])->assertOk();
        $this->as('analyst@emgs.demo')->getJson('/api/v1/dashboards')->assertOk();
        $this->as('analyst@emgs.demo')->deleteJson('/api/v1/me/mfa')->assertStatus(422);
    }

    public function test_custom_roles_are_editable_and_platform_roles_are_not(): void
    {
        $role = $this->as(self::ADMIN)->postJson('/api/v1/admin/roles', [
            'key' => 'regional_lead', 'name' => 'Regional lead', 'experience' => 'executive', 'permissions' => ['dashboards.view', 'reports.view'],
        ])->assertCreated()->json('data');
        $this->as(self::ADMIN)->postJson('/api/v1/admin/roles', ['key' => 'analyst', 'name' => 'Dup', 'experience' => 'analyst', 'permissions' => []])->assertStatus(422);
        $this->as(self::ADMIN)->postJson('/api/v1/admin/roles', ['key' => 'god', 'name' => 'God', 'experience' => 'admin', 'permissions' => ['*']])->assertStatus(422);

        $updated = $this->as(self::ADMIN)->patchJson("/api/v1/admin/roles/{$role['id']}", ['permissions' => ['dashboards.view', 'query.run']])->assertOk();
        $this->assertEqualsCanonicalizing(['dashboards.view', 'query.run'], array_column($updated->json('data.permissions'), 'key'));

        $viewer = TenantScopeBypass::run(fn () => Role::where('key', 'viewer')->value('id'));
        $this->as(self::ADMIN)->patchJson("/api/v1/admin/roles/{$viewer}", ['name' => 'Renamed'])->assertStatus(422);

        $this->as(self::ADMIN)->patchJson('/api/v1/admin/users/'.$this->user('viewer@emgs.demo')->id, ['roles' => ['regional_lead']])->assertOk();
        $this->as(self::ADMIN)->deleteJson("/api/v1/admin/roles/{$role['id']}")->assertStatus(422);
        $this->as(self::ADMIN)->patchJson('/api/v1/admin/users/'.$this->user('viewer@emgs.demo')->id, ['roles' => ['viewer']])->assertOk();
        $this->as(self::ADMIN)->deleteJson("/api/v1/admin/roles/{$role['id']}")->assertNoContent();
    }

    public function test_people_manage_their_own_profile_and_notification_channels(): void
    {
        $this->as('analyst@emgs.demo')->patchJson('/api/v1/me', ['name' => 'Priya N. Nair', 'title' => 'Lead Analyst'])
            ->assertOk()->assertJsonPath('data.name', 'Priya N. Nair')->assertJsonPath('data.title', 'Lead Analyst');

        $this->as('analyst@emgs.demo')->patchJson('/api/v1/me/preferences', ['preferences' => ['notifications' => ['mention' => ['email' => false]], 'date_format' => 'iso']])->assertOk();
        $this->as('analyst@emgs.demo')->patchJson('/api/v1/me/preferences', ['preferences' => ['notifications' => ['gossip' => ['email' => true]]]])->assertStatus(422);
        $this->as('analyst@emgs.demo')->patchJson('/api/v1/me/preferences', ['preferences' => ['favourite_colour' => 'teal']])->assertStatus(422);

        /** @var User $analyst */
        $analyst = $this->user('analyst@emgs.demo');
        $this->assertSame(['in_app', 'push'], Notifier::channelsFor($analyst, 'mention', ['email', 'push']));
        $this->assertSame(['in_app', 'email'], Notifier::channelsFor($analyst, 'alert', ['email']));
    }
}
