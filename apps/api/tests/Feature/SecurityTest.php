<?php

namespace Tests\Feature;

use App\Models\Dashboard;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantScopeBypass;
use DB;
use Tests\SeededTestCase;

/** Tenant isolation, RBAC, row-level and column-level security. */
class SecurityTest extends SeededTestCase
{
    private function otherTenantUser(): User
    {
        return TenantScopeBypass::run(function () {
            $org = Organisation::create(['name' => 'Harbour Health', 'slug' => 'harbour']);
            $u = User::create(['organisation_id' => $org->id, 'name' => 'Other Admin', 'email' => 'admin@harbour.test', 'password' => 'Demo@2026!']);
            $u->roles()->sync([Role::whereNull('organisation_id')->where('key', 'tenant_admin')->value('id')]);

            return $u;
        });
    }

    public function test_tenants_cannot_see_each_others_resources(): void
    {
        $other = $this->otherTenantUser();
        $dashboardId = TenantScopeBypass::run(fn () => Dashboard::where('title', 'Executive Overview')->value('id'));

        $this->as($other->email)->getJson('/api/v1/dashboards')->assertOk()->assertJsonCount(0, 'data');
        $this->as($other->email)->getJson("/api/v1/dashboards/{$dashboardId}")->assertNotFound();
        $this->as($other->email)->getJson('/api/v1/semantic-models')->assertOk()->assertJsonCount(0, 'data');
        $this->as($other->email)->postJson('/api/v1/query', ['model' => 'applications', 'metrics' => ['total_applications']])->assertNotFound();
        $this->as($other->email)->getJson('/api/v1/admin/users')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_viewer_role_is_read_only(): void
    {
        $this->as('viewer@emgs.demo')->getJson('/api/v1/dashboards')->assertOk();
        $this->as('viewer@emgs.demo')->postJson('/api/v1/query', ['model' => 'applications', 'metrics' => ['total_applications']])->assertForbidden();
        $this->as('viewer@emgs.demo')->postJson('/api/v1/dashboards', ['title' => 'x'])->assertForbidden();
        $this->as('viewer@emgs.demo')->getJson('/api/v1/admin/users')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    }

    public function test_executives_get_provenance_but_not_sql(): void
    {
        $q = ['model' => 'applications', 'metrics' => ['total_applications'], 'time' => ['range' => 'last_30_days']];
        $exec = $this->as('ceo@emgs.demo')->postJson('/api/v1/query', $q)->assertOk();
        $this->assertArrayNotHasKey('sql', $exec->json('meta'));
        $this->assertNotEmpty($exec->json('meta.query_hash'));

        $analyst = $this->as('analyst@emgs.demo')->postJson('/api/v1/query', $q)->assertOk();
        $this->assertStringStartsWith('SELECT', $analyst->json('meta.sql'));
        $this->assertSame($exec->json('rows'), $analyst->json('rows'));
    }

    public function test_row_level_security_limits_regional_manager(): void
    {
        $q = ['model' => 'applications', 'metrics' => ['total_applications'], 'dimensions' => ['country_code'], 'time' => ['range' => 'last_12_months']];
        $codes = collect($this->as('manager.asia@emgs.demo')->postJson('/api/v1/query', $q)->assertOk()->json('rows'))->pluck('country_code');
        $this->assertNotEmpty($codes);
        $this->assertEmpty($codes->diff(['CN', 'VN', 'TH', 'JP', 'KR', 'ID']));

        $all = collect($this->as('ceo@emgs.demo')->postJson('/api/v1/query', $q)->json('rows'))->pluck('country_code');
        $this->assertContains('IN', $all->all());

        // Filtering outside the scope cannot widen it.
        $this->assertSame([], $this->as('manager.asia@emgs.demo')->postJson('/api/v1/query', $q + ['filters' => [['dimension' => 'country_code', 'op' => 'eq', 'value' => 'IN']]])->json('rows'));
    }

    public function test_sensitive_fields_need_explicit_permission(): void
    {
        $q = ['model' => 'applications', 'metrics' => ['total_applications'], 'dimensions' => ['applicant_ref'], 'limit' => 3];
        $this->as('analyst@emgs.demo')->postJson('/api/v1/query', $q)->assertForbidden();
        $this->as('engineer@emgs.demo')->postJson('/api/v1/query', $q)->assertOk()->assertJsonCount(3, 'rows');
    }

    public function test_invalid_queries_return_actionable_422(): void
    {
        $this->as('analyst@emgs.demo')->postJson('/api/v1/query', ['model' => 'applications', 'metrics' => ['total_applications'], 'dimensions' => ['nope']])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_query');
    }

    public function test_analytical_role_cannot_write(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::connection('analytics')->statement('DELETE FROM analytics.countries');
    }
}
