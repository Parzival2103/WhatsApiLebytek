<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ApiIdempotencyKey
{
    private const TTL_SECONDS = 86400;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            return response()->json([
                'message' => 'Idempotency-Key header is required for write operations.',
            ], 422);
        }

        $cacheKey = 'api.idempotency:'.hash('sha256', $request->user()?->getAuthIdentifier().':'.$key);

        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached['body'], $cached['status']);
        }

        $response = $next($request);

        if ($response->isSuccessful() || $response->isClientError()) {
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => json_decode($response->getContent(), true),
            ], self::TTL_SECONDS);
        }

        return $response;
    }
}
