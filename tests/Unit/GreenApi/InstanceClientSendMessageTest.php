<?php

use App\Services\GreenApi\InstanceClient;
use Illuminate\Support\Facades\Http;

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
