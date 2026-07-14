<?php

namespace App\Services\GreenApi;

use App\Exceptions\GreenApiException;
use Illuminate\Support\Facades\Http;

class PartnerClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $partnerToken,
    ) {}

    /**
     * @return array{idInstance: string, apiTokenInstance: string}
     */
    public function createInstance(string $label): array
    {
        $token = $this->requirePartnerToken();
        $url = rtrim($this->baseUrl, '/')."/partner/createInstance/{$token}";

        $response = Http::timeout(30)->post($url, ['name' => $label]);

        if (! $response->successful()) {
            throw new GreenApiException(
                'Partner createInstance failed: '.$response->body(),
                $response->status(),
                $response->json(),
            );
        }

        $json = $response->json();
        $idInstance = (string) ($json['idInstance'] ?? '');
        $apiTokenInstance = (string) ($json['apiTokenInstance'] ?? '');

        if ($idInstance === '' || $apiTokenInstance === '') {
            throw new GreenApiException('Partner createInstance returned incomplete credentials.');
        }

        return [
            'idInstance' => $idInstance,
            'apiTokenInstance' => $apiTokenInstance,
        ];
    }

    public function deleteInstanceAccount(string $idInstance): void
    {
        $token = $this->requirePartnerToken();
        // Green Partner contract: POST /partner/deleteInstanceAccount/{partnerToken}
        // with JSON body {"idInstance": <int64>}. Errors often return HTTP 200 + {"code":…}.
        $url = rtrim($this->baseUrl, '/')."/partner/deleteInstanceAccount/{$token}";

        $response = Http::timeout(30)->asJson()->post($url, [
            'idInstance' => (int) $idInstance,
        ]);

        /** @var array<string, mixed>|null $json */
        $json = $response->json();
        if (! is_array($json)) {
            $json = null;
        }

        if (! $response->successful()) {
            throw new GreenApiException(
                'Partner deleteInstanceAccount failed: '.$response->body(),
                $response->status(),
                $json,
            );
        }

        if (is_array($json) && array_key_exists('code', $json)) {
            $description = (string) ($json['description'] ?? 'Green partner error');

            throw new GreenApiException(
                'Partner deleteInstanceAccount failed: '.$description,
                (int) $json['code'],
                $json,
            );
        }

        if (! is_array($json) || ($json['deleteInstanceAccount'] ?? false) !== true) {
            throw new GreenApiException(
                'Partner deleteInstanceAccount returned unexpected response: '.$response->body(),
                $response->status(),
                $json,
            );
        }
    }

    private function requirePartnerToken(): string
    {
        $token = trim((string) $this->partnerToken);

        if ($token === '') {
            throw new GreenApiException('GREEN_API_PARTNER_TOKEN is not configured.');
        }

        return $token;
    }
}
