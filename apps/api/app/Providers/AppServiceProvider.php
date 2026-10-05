<?php

namespace App\Providers;

use App\Domain\Identity\JwtService;
use App\Domain\Query\Dialect\ClickHouseDialect;
use App\Domain\Query\Dialect\Dialect;
use App\Domain\Query\Dialect\PostgresDialect;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(\App\Support\Projects\ProjectContext::class);
        $this->app->scoped(\App\Domain\Semantic\CatalogRepository::class);
        $this->app->singleton(JwtService::class, fn () => JwtService::fromConfig());
        $this->app->bind(\App\Domain\Notifications\PushGateway::class, \App\Domain\Notifications\LogPushGateway::class);
        $this->app->singleton(Dialect::class, fn () => match (config('aixbi.query.dialect')) {
            'clickhouse' => new ClickHouseDialect,
            default => new PostgresDialect,
        });
    }

    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));
        // Credential guessing: per account and address.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip().'|'.strtolower((string) $request->input('email'))));
        // Code guessing: per MFA challenge.
        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinute(10)->by($request->ip().'|'.hash('sha256', (string) $request->input('mfa_token'))));
        // Session restore happens on every page load. Refresh tokens are single-use with reuse detection,
        // so the limit is per token, with a ceiling per address (offices share one IP behind NAT).
        RateLimiter::for('refresh', fn (Request $request) => [
            Limit::perMinute(30)->by('token|'.hash('sha256', (string) $request->input('refresh_token'))),
            Limit::perMinute(600)->by('ip|'.$request->ip()),
        ]);
        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
    }
}
