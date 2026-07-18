<?php

namespace App\Support;

use Illuminate\Http\Request;

class WebhookEventId
{
    public static function resolve(Request $request): string
    {
        $header = $request->header('X-Event-Id');

        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        $payload = self::jsonBody($request);

        $typeWebhook = self::scalar($payload['typeWebhook'] ?? null);

        $instanceData = is_array($payload['instanceData'] ?? null) ? $payload['instanceData'] : [];
        $idInstance = self::scalar($instanceData['idInstance'] ?? $payload['idInstance'] ?? null);

        $idMessage = self::scalar($payload['idMessage'] ?? null);
        if ($idMessage !== '') {
            return self::composite([
                $typeWebhook,
                $idInstance,
                $idMessage,
                self::scalar($payload['status'] ?? null),
            ]);
        }

        $idWebhook = self::scalar($payload['idWebhook'] ?? null);
        if ($idWebhook !== '') {
            return self::composite([$typeWebhook, $idInstance, $idWebhook]);
        }

        $timestamp = self::scalar($payload['timestamp'] ?? null);

        if ($typeWebhook !== '' && $idInstance !== '' && $timestamp !== '') {
            $senderData = is_array($payload['senderData'] ?? null) ? $payload['senderData'] : [];

            return self::composite([
                $typeWebhook,
                $idInstance,
                $timestamp,
                self::scalar($payload['stateInstance'] ?? null),
                self::scalar($senderData['chatId'] ?? $payload['chatId'] ?? $payload['from'] ?? null),
                substr(sha1($request->getContent()), 0, 16),
            ]);
        }

        return sha1($request->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonBody(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  list<string>  $parts
     */
    private static function composite(array $parts): string
    {
        return implode('|', $parts);
    }

    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
