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
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip().'|'.strtolower((string) $request->input('email'))));
        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
    }
}
