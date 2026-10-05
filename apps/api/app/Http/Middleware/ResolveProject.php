<?php

namespace App\Http\Middleware;

use App\Support\Projects\ProjectContext;
use App\Support\Projects\Projects;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits the request to the projects the person may open. The `X-Project-Id`
 * header narrows it to one project, which must be one of them; without the
 * header (older clients, the AI service), every accessible project is visible.
 */
class ResolveProject
{
    public function __construct(private readonly ProjectContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $accessible = Projects::accessibleIds($user);
            $current = $request->header('X-Project-Id') ?: null;
            if ($current !== null && ! in_array($current, $accessible, true)) {
                return response()->json(['error' => ['code' => 'project_forbidden', 'message' => 'You do not have access to this project.']], 403);
            }
            $this->context->set($accessible, $current);
        }

        return $next($request);
    }
}
