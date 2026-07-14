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

        $typeWebhook = $this->scalar($payload['typeWebhook'] ?? null);

        $idMessage = $this->scalar($payload['idMessage'] ?? null);
        if ($idMessage !== '') {
            return $this->composite([
                $typeWebhook,
                $idMessage,
                $this->scalar($payload['status'] ?? null),
            ]);
        }

        $idWebhook = $this->scalar($payload['idWebhook'] ?? null);
        if ($idWebhook !== '') {
            return $this->composite([$typeWebhook, $idWebhook]);
        }

        $instanceData = is_array($payload['instanceData'] ?? null) ? $payload['instanceData'] : [];
        $idInstance = $this->scalar($instanceData['idInstance'] ?? $payload['idInstance'] ?? null);
        $timestamp = $this->scalar($payload['timestamp'] ?? null);

        if ($typeWebhook !== '' && $idInstance !== '' && $timestamp !== '') {
            return $this->composite([
                $typeWebhook,
                $idInstance,
                $timestamp,
                $this->scalar($payload['stateInstance'] ?? null),
            ]);
        }

        return sha1($request->getContent());
    }

    /**
     * @param  list<string>  $parts
     */
    private function composite(array $parts): string
    {
        return implode('|', $parts);
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
