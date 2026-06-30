<?php

namespace App\Http\Middleware;

use App\Models\Core\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveActingTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isPlatformAdmin()) {
            return $next($request);
        }

        $tenantPublicId = $request->header('X-Tenant-Id');

        if (! is_string($tenantPublicId) || $tenantPublicId === '') {
            return $next($request);
        }

        $tenant = Tenant::query()->where('public_id', $tenantPublicId)->first();

        if ($tenant === null) {
            abort(404, 'Acting tenant not found.');
        }

        TenantContext::set($tenant->id, bypassScope: false);

        return $next($request);
    }
}
