<?php

use App\Domain\Analytics\AnalyticsEngineException;
use App\Domain\Query\QueryDeniedException;
use App\Domain\Query\QueryExecutionException;
use App\Domain\Query\QueryValidationException;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\EnforceSecurityPolicy;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$error = fn (string $code, string $message, int $status, array $extra = []) => response()->json(['error' => ['code' => $code, 'message' => $message] + $extra], $status);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.jwt' => AuthenticateJwt::class,
            'perm' => RequirePermission::class,
            'policy' => EnforceSecurityPolicy::class,
        ]);
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($error): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (QueryValidationException $e) => $error('invalid_query', $e->getMessage(), 422));
        $exceptions->render(fn (QueryDeniedException $e) => $error('forbidden', $e->getMessage(), 403));
        $exceptions->render(fn (QueryExecutionException $e) => $error('query_failed', $e->getMessage(), 503));
        $exceptions->render(fn (AnalyticsEngineException $e) => $error('engine_unavailable', $e->getMessage(), 503));
        $exceptions->render(fn (ValidationException $e) => $error('validation_failed', 'Some fields need attention.', 422, ['fields' => $e->errors()]));
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($error) {
            if ($e->getPrevious() instanceof ModelNotFoundException || $request->is('api/*')) {
                return $error('not_found', 'We couldn\'t find what you were looking for. It may have been removed or you may not have access.', 404);
            }
        });
        $exceptions->render(fn (InvalidArgumentException $e, Request $request) => $request->is('api/*') ? $error('invalid_request', $e->getMessage(), 422) : null);
        $exceptions->render(fn (RuntimeException $e, Request $request) => $request->is('api/*') && ! config('app.debug') ? $error('unavailable', $e->getMessage(), 503) : null);
    })->create();
