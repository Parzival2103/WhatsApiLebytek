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

test('green message status events sharing idMessage are not treated as duplicates', function () {
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
    ];

    $post = function (array $payload) use ($server) {
        return $this->call(
            'POST',
            route('api.v1.webhooks.incoming'),
            [],
            [],
            [],
            $server,
            json_encode($payload),
        );
    };

    $post([
        'typeWebhook' => 'outgoingAPIMessageReceived',
        'instanceData' => ['idInstance' => 1101234567],
        'timestamp' => 1720000100,
        'idMessage' => 'green-msg-shared',
    ])->assertOk()->assertJson(['duplicate' => false]);

    foreach (['sent', 'delivered', 'read'] as $status) {
        $post([
            'typeWebhook' => 'outgoingMessageStatus',
            'instanceData' => ['idInstance' => 1101234567],
            'timestamp' => 1720000101,
            'idMessage' => 'green-msg-shared',
            'status' => $status,
        ])->assertOk()->assertJson(['duplicate' => false]);
    }

    $post([
        'typeWebhook' => 'outgoingMessageStatus',
        'instanceData' => ['idInstance' => 1101234567],
        'timestamp' => 1720000101,
        'idMessage' => 'green-msg-shared',
        'status' => 'read',
    ])->assertOk()->assertJson(['duplicate' => true]);
});

test('same idMessage from different instances is not treated as a duplicate', function () {
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
    ];

    $post = function (int $idInstance) use ($server) {
        return $this->call(
            'POST',
            route('api.v1.webhooks.incoming'),
            [],
            [],
            [],
            $server,
            json_encode([
                'typeWebhook' => 'incomingMessageReceived',
                'instanceData' => ['idInstance' => $idInstance],
                'timestamp' => 1720000400,
                'idMessage' => 'green-msg-collision',
            ]),
        );
    };

    $post(1101111111)->assertOk()->assertJson(['duplicate' => false]);
    $post(1102222222)->assertOk()->assertJson(['duplicate' => false]);
    $post(1102222222)->assertOk()->assertJson(['duplicate' => true]);
});

test('distinct state transitions within the same second are not treated as duplicates', function () {
    $instancia = Instancia::factory()->create([
        'id_instance' => '1105554443',
        'status' => 'authorized',
        'green_state' => 'authorized',
        'authorized_at' => now(),
    ]);

    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
    ];

    $post = function (string $state) use ($server) {
        return $this->call(
            'POST',
            route('api.v1.webhooks.incoming'),
            [],
            [],
            [],
            $server,
            json_encode([
                'typeWebhook' => 'stateInstanceChanged',
                'instanceData' => ['idInstance' => 1105554443],
                'stateInstance' => $state,
                'timestamp' => 1720000200,
            ]),
        );
    };

    $post('notAuthorized')->assertOk()->assertJson(['duplicate' => false]);
    $post('authorized')->assertOk()->assertJson(['duplicate' => false]);

    expect($instancia->refresh()->green_state)->toBe('authorized')
        ->and($instancia->status)->toBe('authorized');
});

test('non scalar idMessage falls through to the composite key instead of colliding', function () {
    Instancia::factory()->create([
        'id_instance' => '1107776665',
        'status' => 'waiting_qr',
        'green_state' => 'notAuthorized',
    ]);

    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
    ];

    $post = function (int $timestamp) use ($server) {
        return $this->call(
            'POST',
            route('api.v1.webhooks.incoming'),
            [],
            [],
            [],
            $server,
            json_encode([
                'typeWebhook' => 'stateInstanceChanged',
                'instanceData' => ['idInstance' => 1107776665],
                'stateInstance' => 'authorized',
                'timestamp' => $timestamp,
                'idMessage' => [],
            ]),
        );
    };

    $post(1720000300)->assertOk()->assertJson(['duplicate' => false]);
    $post(1720000301)->assertOk()->assertJson(['duplicate' => false]);
    $post(1720000301)->assertOk()->assertJson(['duplicate' => true]);
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
