<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route === null) {
            abort(403, 'API route requires explicit permission.');
        }

        $middleware = collect($route->gatherMiddleware());

        $hasPermission = $middleware->contains(
            fn (string $name): bool => str_starts_with($name, 'permission:')
                || str_starts_with($name, 'role_or_permission:')
        );

        if (! $hasPermission) {
            abort(403, 'API route requires explicit permission.');
        }

        return $next($request);
    }
}
