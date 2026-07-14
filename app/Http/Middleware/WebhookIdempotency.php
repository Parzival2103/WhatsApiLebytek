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
        $eventId = $this->resolveEventId($request);
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

    private function resolveEventId(Request $request): string
    {
        $header = $request->header('X-Event-Id');

        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        $payload = $request->all();

        $idMessage = $payload['idMessage'] ?? null;
        if ($idMessage !== null && $idMessage !== '') {
            return (string) $idMessage;
        }

        $idWebhook = $payload['idWebhook'] ?? null;
        if ($idWebhook !== null && $idWebhook !== '') {
            return (string) $idWebhook;
        }

        $typeWebhook = (string) ($payload['typeWebhook'] ?? '');
        $instanceData = is_array($payload['instanceData'] ?? null) ? $payload['instanceData'] : [];
        $idInstance = (string) ($instanceData['idInstance'] ?? $payload['idInstance'] ?? '');
        $timestamp = $payload['timestamp'] ?? null;

        if ($typeWebhook !== '' && $idInstance !== '' && $timestamp !== null && $timestamp !== '') {
            return $typeWebhook.'|'.$idInstance.'|'.(string) $timestamp;
        }

        return sha1($request->getContent());
    }
}
