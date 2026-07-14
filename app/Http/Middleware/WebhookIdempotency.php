<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WebhookIdempotency
{
    private const int RESERVATION_SECONDS = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $eventId = $this->resolveEventId($request);
        $cacheKey = 'webhook:event:'.sha1($eventId);

        if (! Cache::add($cacheKey, 'processing', now()->addSeconds(self::RESERVATION_SECONDS))) {
            return response()->json([
                'received' => true,
                'duplicate' => true,
            ]);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            Cache::forget($cacheKey);

            throw $e;
        }

        if ($response->isSuccessful()) {
            Cache::put($cacheKey, true, now()->addDay());
        } else {
            Cache::forget($cacheKey);
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

        $instanceData = is_array($payload['instanceData'] ?? null) ? $payload['instanceData'] : [];
        $idInstance = $this->scalar($instanceData['idInstance'] ?? $payload['idInstance'] ?? null);

        $idMessage = $this->scalar($payload['idMessage'] ?? null);
        if ($idMessage !== '') {
            return $this->composite([
                $typeWebhook,
                $idInstance,
                $idMessage,
                $this->scalar($payload['status'] ?? null),
            ]);
        }

        $idWebhook = $this->scalar($payload['idWebhook'] ?? null);
        if ($idWebhook !== '') {
            return $this->composite([$typeWebhook, $idInstance, $idWebhook]);
        }

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
