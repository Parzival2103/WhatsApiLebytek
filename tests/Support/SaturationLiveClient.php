<?php

namespace Tests\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Thin Live HTTP client for api.lebytek.com saturation scenarios.
 *
 * Respects the product `messages-send` HTTP limiter (10/min) via Retry-After backoff
 * so monthly-quota 429 can be distinguished from throttle 429.
 */
final class SaturationLiveClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $instancePublicId,
        private readonly string $recipient,
    ) {}

    public static function fromCredentials(array $credentials): self
    {
        return new self(
            $credentials['baseUrl'],
            $credentials['token'],
            $credentials['instancePublicId'],
            $credentials['recipient'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function accountStatus(): array
    {
        $response = $this->http()->post('/account/status', []);

        if (! $response->successful()) {
            throw new RuntimeException(
                'account/status failed HTTP '.$response->status().': '.$response->body()
            );
        }

        return $response->json() ?? [];
    }

    /**
     * @return array{status: int, body: array<string, mixed>|null, raw: string}
     */
    public function postMessage(string $body, ?string $idempotencyKey = null): array
    {
        $idempotencyKey ??= (string) Str::uuid();

        $response = $this->postMessageWithThrottleRetry($body, $idempotencyKey);

        return [
            'status' => $response->status(),
            'body' => $response->json(),
            'raw' => $response->body(),
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>|null, raw: string}
     */
    public function getMessage(string $publicId): array
    {
        $response = $this->http()->get('/messages/'.$publicId);

        return [
            'status' => $response->status(),
            'body' => $response->json(),
            'raw' => $response->body(),
        ];
    }

    public function isMonthlyQuotaExceeded(Response|array $response): bool
    {
        if ($response instanceof Response) {
            $status = $response->status();
            $raw = $response->body();
        } else {
            $status = (int) ($response['status'] ?? 0);
            $raw = (string) ($response['raw'] ?? json_encode($response['body'] ?? []));
        }

        return $status === 429 && str_contains($raw, 'Monthly message quota exceeded');
    }

    private function postMessageWithThrottleRetry(string $body, string $idempotencyKey): Response
    {
        $attempts = 0;

        while (true) {
            $attempts++;
            $response = $this->http()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/messages', [
                    'recipient' => $this->recipient,
                    'body' => $body,
                    'instancePublicId' => $this->instancePublicId,
                ]);

            if ($response->status() !== 429 || $this->isMonthlyQuotaExceeded($response)) {
                return $response;
            }

            if ($attempts >= 20) {
                throw new RuntimeException(
                    'Gave up waiting for messages-send HTTP throttle after '.$attempts.' attempts: '.$response->body()
                );
            }

            $retryAfter = (int) ($response->header('Retry-After') ?: 60);
            $sleep = max(1, min(90, $retryAfter));
            fwrite(STDOUT, "HTTP throttle 429 — sleeping {$sleep}s before retry…\n");
            sleep($sleep);
        }
    }

    private function http(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout(60);

        // Manual Live runs on some Windows setups lack a local CA bundle (cURL 60).
        $verify = getenv('SATURATION_SSL_VERIFY');
        if ($verify === '0' || strcasecmp((string) $verify, 'false') === 0) {
            $request = $request->withOptions(['verify' => false]);
        }

        return $request;
    }
}
