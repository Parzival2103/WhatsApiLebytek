<?php

use App\Jobs\TransactionalMessageJob;
use App\Models\Integration\Instancia;
use App\Models\Integration\Mensaje;
use Illuminate\Support\Facades\Http;

test('transactional job sends via green and marks message sent', function () {
    Http::fake([
        '*sendMessage*' => Http::response(['idMessage' => 'GA-99'], 200),
    ]);

    $instancia = Instancia::factory()->create([
        'status' => 'authorized',
        'id_instance' => '1101000001',
        'api_token_instance' => 'tok',
    ]);
    $mensaje = Mensaje::factory()->create([
        'tenant_id' => $instancia->tenant_id,
        'instancia_id' => $instancia->id,
        'status' => 'queued',
        'recipient' => '5215512345678',
        'body' => 'Hola',
    ]);

    (new TransactionalMessageJob($mensaje->id))->handle(
        app(\App\Services\AccountStatusService::class),
        app(\App\Services\TenantUsageService::class),
    );

    $mensaje->refresh();
    expect($mensaje->status)->toBe('sent')
        ->and($mensaje->green_message_id)->toBe('GA-99')
        ->and($mensaje->sent_at)->not->toBeNull();
});

test('transactional job sends group recipient as chatId without rewriting', function () {
    Http::fake([
        '*sendMessage*' => Http::response(['idMessage' => 'GA-GROUP-1'], 200),
    ]);

    $instancia = Instancia::factory()->create([
        'status' => 'authorized',
        'id_instance' => '1101000001',
        'api_token_instance' => 'tok',
    ]);
    $group = '120363012345678901@g.us';
    $mensaje = Mensaje::factory()->create([
        'tenant_id' => $instancia->tenant_id,
        'instancia_id' => $instancia->id,
        'status' => 'queued',
        'recipient' => $group,
        'body' => 'Grupo',
    ]);

    (new TransactionalMessageJob($mensaje->id))->handle(
        app(\App\Services\AccountStatusService::class),
        app(\App\Services\TenantUsageService::class),
    );

    $mensaje->refresh();
    expect($mensaje->status)->toBe('sent')
        ->and($mensaje->green_message_id)->toBe('GA-GROUP-1');

    Http::assertSent(function ($request) use ($group) {
        return str_contains($request->url(), 'sendMessage')
            && ($request['chatId'] ?? null) === $group
            && ($request['message'] ?? null) === 'Grupo';
    });
});
