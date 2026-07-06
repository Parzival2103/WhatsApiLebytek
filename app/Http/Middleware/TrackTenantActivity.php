<?php

namespace App\Http\Middleware;

use App\Models\Core\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackTenantActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user !== null && $user->tenant_id !== null) {
            Tenant::query()
                ->whereKey($user->tenant_id)
                ->update(['last_api_activity_at' => now()]);
        }

        return $response;
    }
}
