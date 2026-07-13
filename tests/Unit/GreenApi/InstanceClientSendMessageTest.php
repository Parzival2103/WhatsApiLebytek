<?php

use App\Services\GreenApi\InstanceClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('sendMessage posts chatId and returns idMessage', function () {
    Http::fake([
        '*sendMessage*' => Http::response(['idMessage' => 'MSG123'], 200),
    ]);

    $client = new InstanceClient(
        'https://api.green-api.com',
        '1101000001',
        'secret-token',
    );

    $id = $client->sendMessage('5215512345678', 'Hola Lebytek');

    expect($id)->toBe('MSG123');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'waInstance1101000001/sendMessage/secret-token')
            && $request['chatId'] === '5215512345678@c.us'
            && $request['message'] === 'Hola Lebytek';
    });
});

test('sendMessage passes through group chatId', function () {
    Http::fake([
        '*sendMessage*' => Http::response(['idMessage' => 'MSG-G'], 200),
    ]);

    $client = new InstanceClient(
        'https://api.green-api.com',
        '1101000001',
        'secret-token',
    );

    $group = '120363012345678901@g.us';
    $id = $client->sendMessage($group, 'Hola grupo');

    expect($id)->toBe('MSG-G');

    Http::assertSent(function ($request) use ($group) {
        return ($request['chatId'] ?? null) === $group;
    });
});
