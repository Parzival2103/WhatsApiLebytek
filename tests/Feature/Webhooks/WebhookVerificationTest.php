<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

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

test('webhook rejects unsupported authorization scheme as unsupported_authorization', function () {
    Log::spy();

    $this->postJson(route('api.v1.webhooks.incoming'), ['event' => 'x'], [
        'X-Event-Id' => 'evt-basic-001',
        'Authorization' => 'Basic '.base64_encode('user:test-webhook-secret'),
    ])->assertUnauthorized();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context) => $context['reason'] === 'unsupported_authorization'
    )->once();
});

test('webhook rejects malformed bearer token as unsupported_authorization', function () {
    Log::spy();

    $this->postJson(route('api.v1.webhooks.incoming'), ['event' => 'x'], [
        'X-Event-Id' => 'evt-bearer-malformed-001',
        'Authorization' => 'Bearer test-webhook secret',
    ])->assertUnauthorized();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context) => $context['reason'] === 'unsupported_authorization'
    )->once();
});

test('webhook rejects absent authorization as missing_credentials', function () {
    Log::spy();

    $this->postJson(route('api.v1.webhooks.incoming'), ['event' => 'x'], [
        'X-Event-Id' => 'evt-none-002',
    ])->assertUnauthorized();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context) => $context['reason'] === 'missing_credentials'
    )->once();
});

test('webhook returns 500 when secret is not configured', function () {
    config(['services.webhooks.secret' => '']);

    $this->postJson(route('api.v1.webhooks.incoming'), ['event' => 'x'], [
        'X-Event-Id' => 'evt-no-secret',
        'Authorization' => 'Bearer anything',
    ])->assertStatus(500);
});

test('incoming webhook is rate limited per IP', function () {
    $payload = ['event' => 'message.received'];
    $body = json_encode($payload);
    $signature = hash_hmac('sha256', $body, 'test-webhook-secret');

    $hit = function (string $eventId) use ($body, $signature) {
        return $this->call(
            'POST',
            route('api.v1.webhooks.incoming'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Event-Id' => $eventId,
                'HTTP_X-Webhook-Signature' => $signature,
                'REMOTE_ADDR' => '203.0.113.50',
            ],
            $body,
        );
    };

    RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(2)->by($request->ip()));

    $hit('evt-rl-1')->assertSuccessful();
    $hit('evt-rl-2')->assertSuccessful();
    $hit('evt-rl-3')->assertStatus(429);
});
