<?php

namespace App\Http\Middleware;

use App\Domain\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('perm:reports.manage') — any listed permission grants access. */
class RequirePermission
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        foreach ($permissions as $permission) {
            if ($user?->hasPermission($permission)) {
                return $next($request);
            }
        }

        $this->audit->deny('permission.denied', 'missing:'.implode('|', $permissions), [
            'resource_type' => 'route', 'resource_id' => $request->route()?->uri(),
        ]);

        return response()->json(['error' => [
            'code' => 'forbidden',
            'message' => 'You do not have permission to perform this action.',
            'required' => $permissions,
        ]], 403);
    }
}
