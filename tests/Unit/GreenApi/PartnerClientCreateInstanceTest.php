<?php

use App\Exceptions\GreenApiException;
use App\Services\GreenApi\PartnerClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('createInstance returns credentials on success', function () {
    Http::fake([
        '*/partner/createInstance/*' => Http::response([
            'idInstance' => 1101728000,
            'apiTokenInstance' => 'token-abc',
            'typeInstance' => 'whatsapp',
        ], 200),
    ]);

    $client = new PartnerClient('https://api.green-api.com', 'partner-token-xyz');
    $result = $client->createInstance('Demo Acme');

    expect($result)->toBe([
        'idInstance' => '1101728000',
        'apiTokenInstance' => 'token-abc',
    ]);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.green-api.com/partner/createInstance/partner-token-xyz'
            && ($request['name'] ?? null) === 'Demo Acme';
    });
});

test('createInstance fails when Green returns HTTP 200 with error code body', function () {
    Http::fake([
        '*/partner/createInstance/*' => Http::response([
            'code' => 500,
            'description' => 'Internal server error. Lookup into server logs for details.',
        ], 200),
    ]);

    $client = new PartnerClient('https://api.green-api.com', 'partner-token-xyz');

            expect(fn () => $client->createInstance('Demo Acme'))
        ->toThrow(
            GreenApiException::class,
            'Partner createInstance failed: Internal server error. Lookup into server logs for details.',
        );
});
