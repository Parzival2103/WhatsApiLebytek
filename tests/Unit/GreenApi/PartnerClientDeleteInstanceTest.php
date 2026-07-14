<?php

use App\Exceptions\GreenApiException;
use App\Services\GreenApi\PartnerClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('deleteInstanceAccount posts idInstance in body and requires success flag', function () {
    Http::fake([
        '*/partner/deleteInstanceAccount/*' => Http::response([
            'deleteInstanceAccount' => true,
        ], 200),
    ]);

    $client = new PartnerClient('https://api.green-api.com', 'partner-token-xyz');
    $client->deleteInstanceAccount('770022682077');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.green-api.com/partner/deleteInstanceAccount/partner-token-xyz'
            && ($request['idInstance'] ?? null) === 770022682077;
    });
});

test('deleteInstanceAccount fails when Green returns HTTP 200 with error code body', function () {
    Http::fake([
        '*/partner/deleteInstanceAccount/*' => Http::response([
            'code' => 401,
            'description' => 'Unauthorized',
        ], 200),
    ]);

    $client = new PartnerClient('https://api.green-api.com', 'partner-token-xyz');

    expect(fn () => $client->deleteInstanceAccount('1101000000'))
        ->toThrow(GreenApiException::class, 'Unauthorized');
});

test('deleteInstanceAccount fails when success flag is missing', function () {
    Http::fake([
        '*/partner/deleteInstanceAccount/*' => Http::response([
            'deleteInstanceAccount' => false,
        ], 200),
    ]);

    $client = new PartnerClient('https://api.green-api.com', 'partner-token-xyz');

    expect(fn () => $client->deleteInstanceAccount('1101000000'))
        ->toThrow(GreenApiException::class);
});

test('deleteInstanceAccount does not use DELETE with idInstance in path', function () {
    Http::fake([
        '*/partner/deleteInstanceAccount/*' => Http::response([
            'deleteInstanceAccount' => true,
        ], 200),
    ]);

    $client = new PartnerClient('https://api.green-api.com', 'partner-token-xyz');
    $client->deleteInstanceAccount('1101000000');

    Http::assertNotSent(function ($request) {
        return $request->method() === 'DELETE';
    });

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/partner/deleteInstanceAccount/partner-token-xyz/1101000000');
    });
});
