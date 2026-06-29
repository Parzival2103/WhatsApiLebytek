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
