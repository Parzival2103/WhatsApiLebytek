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
        $url = rtrim($this->baseUrl, '/')."/partner/deleteInstanceAccount/{$token}/{$idInstance}";

        $response = Http::timeout(30)->delete($url);

        if (! $response->successful()) {
            throw new GreenApiException(
                'Partner deleteInstanceAccount failed: '.$response->body(),
                $response->status(),
                $response->json(),
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
