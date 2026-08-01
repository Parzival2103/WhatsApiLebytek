<?php

namespace App\Services\GreenApi;

use App\Exceptions\GreenApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class InstanceClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $idInstance,
        private readonly string $apiTokenInstance,
    ) {}

    public function getStateInstance(): string
    {
        $url = $this->instanceUrl('getStateInstance');
        $maxAttempts = 3;
        $delaySeconds = 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::timeout(15)->get($url);
            } catch (ConnectionException $e) {
                if ($attempt < $maxAttempts) {
                    sleep($delaySeconds);
                    $delaySeconds *= 2;

                    continue;
                }

                throw new GreenApiException(
                    'getStateInstance failed: '.$e->getMessage(),
                    0,
                );
            }

            if ($response->successful()) {
                return (string) ($response->json('stateInstance') ?? '');
            }

            $status = $response->status();
            $retryable = in_array($status, [0, 429, 500, 502, 503, 504], true);

            if ($retryable && $attempt < $maxAttempts) {
                sleep($delaySeconds);
                $delaySeconds *= 2;

                continue;
            }

            throw new GreenApiException(
                'getStateInstance failed: '.$response->body(),
                $status,
                $response->json(),
            );
        }

        throw new GreenApiException('getStateInstance failed after retries.', 0);
    }

    /**
     * @return array{qr: string, type: string}
     */
    public function qr(): array
    {
        $url = $this->instanceUrl('qr');
        $response = Http::timeout(15)->get($url);

        if (! $response->successful()) {
            throw new GreenApiException(
                'qr failed: '.$response->body(),
                $response->status(),
                $response->json(),
            );
        }

        $json = $response->json();

        return [
            'qr' => (string) ($json['message'] ?? ''),
            'type' => (string) ($json['type'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function setSettings(array $settings): void
    {
        $url = $this->instanceUrl('setSettings');
        $response = Http::timeout(15)->asJson()->post($url, $settings);

        if (! $response->successful()) {
            throw new GreenApiException(
                'setSettings failed (HTTP '.$response->status().'): '.$response->body(),
                $response->status(),
                $response->json(),
            );
        }
    }

    public function sendMessage(string $recipient, string $body): string
    {
        $chatId = str_contains($recipient, '@') ? $recipient : $recipient.'@c.us';
        $url = $this->instanceUrl('sendMessage');
        $response = Http::timeout(30)->post($url, [
            'chatId' => $chatId,
            'message' => $body,
        ]);

        if (! $response->successful()) {
            throw new GreenApiException(
                'sendMessage failed: '.$response->body(),
                $response->status(),
                $response->json(),
            );
        }

        $idMessage = (string) ($response->json('idMessage') ?? '');

        if ($idMessage === '') {
            throw new GreenApiException('sendMessage missing idMessage', $response->status(), $response->json());
        }

        return $idMessage;
    }

    private function instanceUrl(string $method): string
    {
        return rtrim($this->baseUrl, '/')."/waInstance{$this->idInstance}/{$method}/{$this->apiTokenInstance}";
    }
}
