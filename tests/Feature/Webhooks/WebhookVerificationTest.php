<?php

beforeEach(function () {
    config(['services.webhooks.secret' => 'test-webhook-secret']);
});

test('webhook rejects invalid signature', function () {
    $payload = ['event' => 'message.received'];

    $this->postJson(route('api.v1.webhooks.incoming'), $payload, [
        'X-Event-Id' => 'evt-001',
        'X-Webhook-Signature' => 'invalid',
    ])->assertUnauthorized();
});

test('webhook accepts valid signature', function () {
    $payload = ['event' => 'message.received'];
    $body = json_encode($payload);
    $signature = hash_hmac('sha256', $body, 'test-webhook-secret');

    $this->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Event-Id' => 'evt-002',
            'HTTP_X-Webhook-Signature' => $signature,
        ],
        $body,
    )->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
});

test('duplicate webhook event id is idempotent', function () {
    $payload = ['event' => 'message.received'];
    $body = json_encode($payload);
    $signature = hash_hmac('sha256', $body, 'test-webhook-secret');
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Event-Id' => 'evt-003',
        'HTTP_X-Webhook-Signature' => $signature,
    ];

    $this->call('POST', route('api.v1.webhooks.incoming'), [], [], [], $headers, $body)
        ->assertOk()
        ->assertJson(['duplicate' => false]);

    $this->call('POST', route('api.v1.webhooks.incoming'), [], [], [], $headers, $body)
        ->assertOk()
        ->assertJson(['received' => true, 'duplicate' => true]);
});

test('webhook accepts bearer token when hmac signature header is absent', function () {
    $payload = ['event' => 'message.received'];
    $body = json_encode($payload);

    $this->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Event-Id' => 'evt-bearer-001',
            'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
        ],
        $body,
    )->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
});

test('webhook rejects invalid bearer token', function () {
    $payload = ['event' => 'message.received'];

    $this->postJson(route('api.v1.webhooks.incoming'), $payload, [
        'X-Event-Id' => 'evt-bearer-002',
        'Authorization' => 'Bearer wrong-secret',
    ])->assertUnauthorized();
});

test('webhook rejects request with neither signature nor bearer', function () {
    $this->postJson(route('api.v1.webhooks.incoming'), ['event' => 'x'], [
        'X-Event-Id' => 'evt-none-001',
    ])->assertUnauthorized();
});

test('invalid hmac does not fall back to bearer on same request', function () {
    $payload = ['event' => 'message.received'];
    $body = json_encode($payload);

    $this->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Event-Id' => 'evt-hmac-wins-001',
            'HTTP_X-Webhook-Signature' => 'definitely-invalid',
            'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
        ],
        $body,
    )->assertUnauthorized();
});

test('webhook returns 500 when secret is not configured', function () {
    config(['services.webhooks.secret' => '']);

    $this->postJson(route('api.v1.webhooks.incoming'), ['event' => 'x'], [
        'X-Event-Id' => 'evt-no-secret',
        'Authorization' => 'Bearer anything',
    ])->assertStatus(500);
});
