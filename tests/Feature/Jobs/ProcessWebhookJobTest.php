<?php

use App\Exceptions\WebhookInstanceNotReadyException;
use App\Jobs\ProcessWebhookJob;
use App\Models\Integration\Instancia;
use App\Models\Integration\Webhook;

test('webhook model persists payload as array and casts processed_at', function () {
    $webhook = Webhook::factory()->create([
        'event_id' => 'evt-'.uniqid(),
        'type_webhook' => 'incomingMessageReceived',
        'id_instance' => '1101234567',
        'payload' => ['typeWebhook' => 'incomingMessageReceived', 'foo' => 'bar'],
    ]);

    $fresh = Webhook::query()->find($webhook->id);

    expect($fresh->payload)->toBe(['typeWebhook' => 'incomingMessageReceived', 'foo' => 'bar'])
        ->and($fresh->processed_at)->toBeNull()
        ->and($fresh->tenant_id)->toBeNull();
});

test('webhook factory processed state stamps processed_at', function () {
    $webhook = Webhook::factory()->processed()->create();

    expect($webhook->processed_at)->not->toBeNull();
});

test('stateInstanceChanged authorized marks instance authorized and stamps processed_at', function () {
    $instancia = Instancia::factory()->create([
        'id_instance' => '1101234567',
        'status' => 'waiting_qr',
        'green_state' => 'notAuthorized',
        'authorized_at' => null,
    ]);

    $webhook = Webhook::factory()->create([
        'type_webhook' => 'stateInstanceChanged',
        'id_instance' => '1101234567',
        'payload' => [
            'typeWebhook' => 'stateInstanceChanged',
            'instanceData' => ['idInstance' => 1101234567],
            'stateInstance' => 'authorized',
        ],
    ]);

    (new ProcessWebhookJob($webhook->id))->handle();

    $instancia->refresh();
    $webhook->refresh();

    expect($instancia->green_state)->toBe('authorized')
        ->and($instancia->status)->toBe('authorized')
        ->and($instancia->authorized_at)->not->toBeNull()
        ->and($webhook->processed_at)->not->toBeNull()
        ->and($webhook->tenant_id)->toBe($instancia->tenant_id);
});

test('stateInstanceChanged notAuthorized reverts an authorized instance to waiting_qr', function () {
    $instancia = Instancia::factory()->create([
        'id_instance' => '1105554443',
        'status' => 'authorized',
        'green_state' => 'authorized',
        'authorized_at' => now(),
    ]);

    $webhook = Webhook::factory()->create([
        'type_webhook' => 'stateInstanceChanged',
        'id_instance' => '1105554443',
        'payload' => [
            'typeWebhook' => 'stateInstanceChanged',
            'instanceData' => ['idInstance' => 1105554443],
            'stateInstance' => 'notAuthorized',
        ],
    ]);

    (new ProcessWebhookJob($webhook->id))->handle();

    $instancia->refresh();

    expect($instancia->green_state)->toBe('notAuthorized')
        ->and($instancia->status)->toBe('waiting_qr')
        ->and($instancia->authorized_at)->toBeNull();
});

test('non-state event types are persist-only and just stamp processed_at', function () {
    $webhook = Webhook::factory()->create([
        'type_webhook' => 'incomingMessageReceived',
        'id_instance' => '1109990001',
        'payload' => [
            'typeWebhook' => 'incomingMessageReceived',
            'instanceData' => ['idInstance' => 1109990001],
        ],
    ]);

    (new ProcessWebhookJob($webhook->id))->handle();

    expect($webhook->refresh()->processed_at)->not->toBeNull();
});

test('non-state event resolves tenant_id from a known instance', function () {
    $instancia = Instancia::factory()->create(['id_instance' => '1108880002']);

    $webhook = Webhook::factory()->create([
        'type_webhook' => 'incomingMessageReceived',
        'id_instance' => '1108880002',
        'payload' => ['typeWebhook' => 'incomingMessageReceived', 'instanceData' => ['idInstance' => 1108880002]],
    ]);

    (new ProcessWebhookJob($webhook->id))->handle();

    expect($webhook->refresh()->tenant_id)->toBe($instancia->tenant_id);
});

test('stateInstanceChanged for an unknown instance throws and leaves the row unprocessed', function () {
    $webhook = Webhook::factory()->create([
        'type_webhook' => 'stateInstanceChanged',
        'id_instance' => '1100000009',
        'payload' => [
            'typeWebhook' => 'stateInstanceChanged',
            'instanceData' => ['idInstance' => 1100000009],
            'stateInstance' => 'authorized',
        ],
    ]);

    expect(fn () => (new ProcessWebhookJob($webhook->id))->handle())
        ->toThrow(WebhookInstanceNotReadyException::class);

    expect($webhook->refresh()->processed_at)->toBeNull();
});

test('the provisioning race self-heals when the job retries after the instance exists', function () {
    $webhook = Webhook::factory()->create([
        'type_webhook' => 'stateInstanceChanged',
        'id_instance' => '1100000010',
        'payload' => [
            'typeWebhook' => 'stateInstanceChanged',
            'instanceData' => ['idInstance' => 1100000010],
            'stateInstance' => 'authorized',
        ],
    ]);

    // First attempt: instance not committed yet.
    expect(fn () => (new ProcessWebhookJob($webhook->id))->handle())
        ->toThrow(WebhookInstanceNotReadyException::class);

    // Instance now exists; retry succeeds.
    $instancia = Instancia::factory()->create([
        'id_instance' => '1100000010',
        'status' => 'waiting_qr',
        'green_state' => 'notAuthorized',
    ]);

    (new ProcessWebhookJob($webhook->id))->handle();

    expect($instancia->refresh()->status)->toBe('authorized')
        ->and($webhook->refresh()->processed_at)->not->toBeNull();
});

test('an already-processed webhook is a no-op on re-run', function () {
    $instancia = Instancia::factory()->create([
        'id_instance' => '1100000011',
        'status' => 'authorized',
        'green_state' => 'authorized',
        'authorized_at' => now(),
    ]);

    // startOfSecond: SQLite datetime drops microseconds, so equalTo would flake otherwise.
    $processedAt = now()->subHour()->startOfSecond();
    $webhook = Webhook::factory()->create([
        'type_webhook' => 'stateInstanceChanged',
        'id_instance' => '1100000011',
        'processed_at' => $processedAt,
        'payload' => [
            'typeWebhook' => 'stateInstanceChanged',
            'instanceData' => ['idInstance' => 1100000011],
            'stateInstance' => 'notAuthorized',
        ],
    ]);

    (new ProcessWebhookJob($webhook->id))->handle();

    // Instance untouched (still authorized) because the row was already processed.
    expect($instancia->refresh()->status)->toBe('authorized')
        ->and($webhook->refresh()->processed_at->equalTo($processedAt))->toBeTrue();
});
