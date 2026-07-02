<?php

use App\Jobs\TransactionalMessageJob;
use App\Models\Integration\Instancia;
use App\Models\Integration\Mensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

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

    (new TransactionalMessageJob($mensaje->id))->handle();

    $mensaje->refresh();
    expect($mensaje->status)->toBe('sent')
        ->and($mensaje->green_message_id)->toBe('GA-99')
        ->and($mensaje->sent_at)->not->toBeNull();
});
