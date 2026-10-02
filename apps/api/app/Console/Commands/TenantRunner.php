<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScopeBypass;
use Throwable;

/**
 * Runs organisation-wide background work as that organisation's administrator
 * (a principal exempt from row-level restrictions), with tenant context set.
 */
trait TenantRunner
{
    protected function forEachOrganisation(callable $fn): void
    {
        foreach (TenantScopeBypass::run(fn () => Organisation::all()) as $org) {
            $admin = TenantScopeBypass::run(fn () => User::with('roles')->where('organisation_id', $org->id)->where('status', 'active')
                ->whereHas('roles', fn ($q) => $q->where('key', 'tenant_admin'))->first());
            if (! $admin) {
                continue;
            }
            app(TenantContext::class)->set($admin);
            app(\App\Domain\Semantic\CatalogRepository::class)->forget();
            try {
                $fn($org, $admin);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$org->name}: {$e->getMessage()}");
            } finally {
                app(TenantContext::class)->clear();
            }
        }
    }
}
