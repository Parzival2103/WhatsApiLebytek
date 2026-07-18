<?php

use App\Support\WebhookEventId;
use Illuminate\Http\Request;

function makeWebhookRequest(array $payload, array $headers = []): Request
{
    $body = json_encode($payload);
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create('/api/v1/webhooks/incoming', 'POST', [], [], [], $server, $body);
}

test('prefers explicit X-Event-Id header when present', function () {
    $request = makeWebhookRequest(['typeWebhook' => 'stateInstanceChanged'], ['X-Event-Id' => '  evt-42  ']);

    expect(WebhookEventId::resolve($request))->toBe('evt-42');
});

test('status events sharing idMessage produce distinct keys per status', function () {
    $sent = makeWebhookRequest([
        'typeWebhook' => 'outgoingMessageStatus',
        'instanceData' => ['idInstance' => 1101234567],
        'idMessage' => 'green-msg-shared',
        'status' => 'sent',
    ]);
    $read = makeWebhookRequest([
        'typeWebhook' => 'outgoingMessageStatus',
        'instanceData' => ['idInstance' => 1101234567],
        'idMessage' => 'green-msg-shared',
        'status' => 'read',
    ]);

    expect(WebhookEventId::resolve($sent))->not->toBe(WebhookEventId::resolve($read));
});

test('same idMessage from different instances produces distinct keys', function () {
    $a = makeWebhookRequest([
        'typeWebhook' => 'incomingMessageReceived',
        'instanceData' => ['idInstance' => 1101111111],
        'idMessage' => 'green-msg-collision',
    ]);
    $b = makeWebhookRequest([
        'typeWebhook' => 'incomingMessageReceived',
        'instanceData' => ['idInstance' => 1102222222],
        'idMessage' => 'green-msg-collision',
    ]);

    expect(WebhookEventId::resolve($a))->not->toBe(WebhookEventId::resolve($b));
});

test('distinct state transitions in the same second produce distinct keys', function () {
    $notAuth = makeWebhookRequest([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1105554443],
        'stateInstance' => 'notAuthorized',
        'timestamp' => 1720000200,
    ]);
    $auth = makeWebhookRequest([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1105554443],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000200,
    ]);

    expect(WebhookEventId::resolve($notAuth))->not->toBe(WebhookEventId::resolve($auth));
});

test('non scalar idMessage falls through to composite instead of colliding', function () {
    $a = makeWebhookRequest([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1107776665],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000300,
        'idMessage' => [],
    ]);
    $b = makeWebhookRequest([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1107776665],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000301,
        'idMessage' => [],
    ]);

    expect(WebhookEventId::resolve($a))->not->toBe(WebhookEventId::resolve($b));
});

test('payload without any ids falls back to body hash', function () {
    $request = makeWebhookRequest([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => '1100000001'],
        'stateInstance' => 'notAuthorized',
    ]);

    expect(WebhookEventId::resolve($request))->toBe(sha1($request->getContent()));
});
