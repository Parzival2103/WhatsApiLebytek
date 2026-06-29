<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::clear();

        $user = $request->user();

        if ($user !== null) {
            $bypass = $user->is_platform_admin || $user->tenant_id === null;
            TenantContext::set($user->tenant_id, $bypass);
        }

        try {
            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }
}
