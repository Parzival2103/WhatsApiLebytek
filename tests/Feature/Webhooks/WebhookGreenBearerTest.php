<?php

use App\Models\Integration\Instancia;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['services.webhooks.secret' => 'test-webhook-secret']);
    Cache::flush();
});

test('green bearer stateInstanceChanged updates instancia without hmac or event id header', function () {
    $instancia = Instancia::factory()->create([
        'id_instance' => '1101234567',
        'status' => 'waiting_qr',
        'green_state' => 'notAuthorized',
        'authorized_at' => null,
    ]);

    $payload = [
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => [
            'idInstance' => 1101234567,
        ],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000000,
        'idMessage' => 'green-msg-001',
    ];
    $body = json_encode($payload);

    $this->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
        ],
        $body,
    )->assertOk()->assertJson(['received' => true, 'duplicate' => false]);

    $instancia->refresh();

    expect($instancia->green_state)->toBe('authorized')
        ->and($instancia->status)->toBe('authorized')
        ->and($instancia->authorized_at)->not->toBeNull();
});

test('green bearer duplicate delivery is idempotent using derived event id', function () {
    Instancia::factory()->create([
        'id_instance' => '1109998887',
        'status' => 'waiting_qr',
        'green_state' => 'notAuthorized',
    ]);

    $payload = [
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1109998887],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000001,
        'idMessage' => 'green-dup-001',
    ];
    $body = json_encode($payload);
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
    ];

    $this->call('POST', route('api.v1.webhooks.incoming'), [], [], [], $server, $body)
        ->assertOk()
        ->assertJson(['duplicate' => false]);

    $this->call('POST', route('api.v1.webhooks.incoming'), [], [], [], $server, $body)
        ->assertOk()
        ->assertJson(['received' => true, 'duplicate' => true]);
});

test('green bearer without ids still accepts via body hash idempotency key', function () {
    $payload = [
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => '1100000001'],
        'stateInstance' => 'notAuthorized',
    ];
    $body = json_encode($payload);

    $this->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
        ],
        $body,
    )->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
});
