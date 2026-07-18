<?php

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
