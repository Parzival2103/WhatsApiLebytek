<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class WebhookIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $eventId = $request->header('X-Event-Id');

        if (! is_string($eventId) || trim($eventId) === '') {
            abort(422, 'Missing X-Event-Id header.');
        }

        $cacheKey = 'webhook:event:'.sha1($eventId);

        if (Cache::has($cacheKey)) {
            return response()->json([
                'received' => true,
                'duplicate' => true,
            ]);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            Cache::put($cacheKey, true, now()->addDay());
        }

        return $response;
    }
}
