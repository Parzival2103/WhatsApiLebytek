<?php

use App\Jobs\ProcessWebhookJob;
use App\Models\Integration\Webhook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.webhooks.secret' => 'test-webhook-secret']);
    Cache::flush();
});

function postWebhook(array $payload): \Illuminate\Testing\TestResponse
{
    return test()->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
        ],
        json_encode($payload),
    );
}

test('every webhook type persists exactly one int_webhooks row and dispatches the job', function () {
    Queue::fake();

    $types = [
        ['typeWebhook' => 'incomingMessageReceived', 'instanceData' => ['idInstance' => 1101234567], 'idMessage' => 'm-1'],
        ['typeWebhook' => 'outgoingMessageStatus', 'instanceData' => ['idInstance' => 1101234567], 'idMessage' => 'm-2', 'status' => 'delivered'],
        ['typeWebhook' => 'outgoingAPIMessageReceived', 'instanceData' => ['idInstance' => 1101234567], 'idMessage' => 'm-3'],
        ['typeWebhook' => 'incomingCall', 'instanceData' => ['idInstance' => 1101234567], 'timestamp' => 1720000500, 'from' => '5215550001@c.us', 'status' => 'offer'],
        ['typeWebhook' => 'stateInstanceChanged', 'instanceData' => ['idInstance' => 1101234567], 'stateInstance' => 'authorized', 'timestamp' => 1720000600],
    ];

    foreach ($types as $payload) {
        postWebhook($payload)->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
    }

    expect(Webhook::query()->count())->toBe(5);
    Queue::assertPushed(ProcessWebhookJob::class, 5);
});

test('duplicate delivery does not create a second row and returns duplicate true even with a cold cache', function () {
    Queue::fake();

    $payload = [
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1109998887],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000001,
    ];

    postWebhook($payload)->assertOk()->assertJson(['duplicate' => false]);

    // The DB — not Redis — is the dedup authority: flush the cache before the retry.
    Cache::flush();

    postWebhook($payload)->assertOk()->assertJson(['received' => true, 'duplicate' => true]);

    expect(Webhook::query()->count())->toBe(1);
    Queue::assertPushed(ProcessWebhookJob::class, 1);
});

test('stateInstanceChanged authorized ends with the instance authorized and the row processed', function () {
    // Sync queue (default in tests): the dispatched job runs inline, no Queue::fake().
    $instancia = \App\Models\Integration\Instancia::factory()->create([
        'id_instance' => '1101234567',
        'status' => 'waiting_qr',
        'green_state' => 'notAuthorized',
        'authorized_at' => null,
    ]);

    postWebhook([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1101234567],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000000,
    ])->assertOk()->assertJson(['received' => true, 'duplicate' => false]);

    $instancia->refresh();
    $webhook = Webhook::query()->firstOrFail();

    expect($instancia->status)->toBe('authorized')
        ->and($instancia->green_state)->toBe('authorized')
        ->and($instancia->authorized_at)->not->toBeNull()
        ->and($webhook->processed_at)->not->toBeNull();
});

test('same idMessage from different instances is not treated as a duplicate', function () {
    Queue::fake();

    $payload = fn (int $idInstance) => [
        'typeWebhook' => 'incomingMessageReceived',
        'instanceData' => ['idInstance' => $idInstance],
        'timestamp' => 1720000400,
        'idMessage' => 'green-msg-collision',
    ];

    postWebhook($payload(1101111111))->assertOk()->assertJson(['duplicate' => false]);
    postWebhook($payload(1102222222))->assertOk()->assertJson(['duplicate' => false]);
    postWebhook($payload(1102222222))->assertOk()->assertJson(['duplicate' => true]);

    expect(Webhook::query()->count())->toBe(2);
});

test('webhook without any ids still persists via the body-hash key', function () {
    Queue::fake();

    postWebhook([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => '1100000001'],
        'stateInstance' => 'notAuthorized',
    ])->assertOk()->assertJson(['received' => true, 'duplicate' => false]);

    expect(Webhook::query()->count())->toBe(1);
});
