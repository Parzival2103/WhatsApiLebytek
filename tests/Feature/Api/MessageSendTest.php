<?php

use App\Jobs\TransactionalMessageJob;
use App\Models\Core\Module;
use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Models\Integration\Mensaje;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Bus::fake([TransactionalMessageJob::class]);
});

test('int_mensajes table exists with required columns', function () {
    expect(Schema::hasTable('int_mensajes'))->toBeTrue()
        ->and(Schema::hasColumns('int_mensajes', [
            'id', 'public_id', 'tenant_id', 'instancia_id', 'direction',
            'recipient', 'body', 'status', 'green_message_id', 'error',
            'payload_hash', 'sent_at', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

test('int_webhooks table exists with required columns', function () {
    expect(Schema::hasTable('int_webhooks'))->toBeTrue()
        ->and(Schema::hasColumns('int_webhooks', [
            'id', 'event_id', 'type_webhook', 'id_instance', 'payload',
            'processed_at', 'tenant_id', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

test('mensaje factory creates outbound queued row', function () {
    $mensaje = Mensaje::factory()->create([
        'direction' => 'outbound',
        'status' => 'queued',
    ]);

    expect($mensaje->public_id)->toBeString()->not->toBeEmpty()
        ->and($mensaje->direction)->toBe('outbound')
        ->and($mensaje->status)->toBe('queued');
});

test('tenant token can POST messages and dispatch job', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create(['tenant_id' => $tenant->id, 'module_key' => 'whatsapp', 'is_enabled' => true]);
    $instancia = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
        'id_instance' => '1101000001',
        'api_token_instance' => 'green-secret',
    ]);

    Http::fake([
        '*/waInstance1101000001/getStateInstance/*' => Http::response([
            'stateInstance' => 'authorized',
        ], 200),
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo(['mensajes.enviar', 'mensajes.ver']);
    $token = $client->createToken('client', ['mensajes.enviar', 'mensajes.ver'])->plainTextToken;

    $headers = idempotencyHeaders();

    $response = $this->withToken($token)
        ->postJson(route('api.v1.messages.store'), [
            'recipient' => '5215512345678',
            'body' => 'Test Lebytek API',
            'instancePublicId' => $instancia->public_id,
        ], $headers);

    $response->assertAccepted()
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('recipient', '5215512345678');

    Bus::assertDispatched(TransactionalMessageJob::class);
});

test('POST messages is idempotent by Idempotency-Key', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create(['tenant_id' => $tenant->id, 'module_key' => 'whatsapp', 'is_enabled' => true]);
    $instancia = Instancia::factory()->create(['tenant_id' => $tenant->id, 'status' => 'authorized']);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo(['mensajes.enviar', 'mensajes.ver']);
    $token = $client->createToken('client', ['mensajes.enviar'])->plainTextToken;

    $payload = [
        'recipient' => '5215512345678',
        'body' => 'Once',
        'instancePublicId' => $instancia->public_id,
    ];
    $headers = ['Idempotency-Key' => 'fixed-key-abc'];

    $first = $this->withToken($token)->postJson(route('api.v1.messages.store'), $payload, $headers)->assertAccepted();
    $second = $this->withToken($token)->postJson(route('api.v1.messages.store'), $payload, $headers)->assertAccepted();

    expect($second->json('publicId'))->toBe($first->json('publicId'));
    expect(Mensaje::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
    Bus::assertDispatchedTimes(TransactionalMessageJob::class, 1);
});

test('POST messages returns 409 when instance not authorized', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create(['tenant_id' => $tenant->id, 'module_key' => 'whatsapp', 'is_enabled' => true]);
    $instancia = Instancia::factory()->create(['tenant_id' => $tenant->id, 'status' => 'waiting_qr']);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo('mensajes.enviar');
    $token = $client->createToken('client', ['mensajes.enviar'])->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.messages.store'), [
            'recipient' => '5215512345678',
            'body' => 'Fail',
            'instancePublicId' => $instancia->public_id,
        ], idempotencyHeaders())
        ->assertStatus(409);
});

test('tenant cannot read another tenant message', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $mensaje = Mensaje::factory()->create(['tenant_id' => $tenantA->id]);

    $clientB = User::factory()->forTenant($tenantB)->create();
    $clientB->givePermissionTo('mensajes.ver');
    $tokenB = $clientB->createToken('client', ['mensajes.ver'])->plainTextToken;

    $this->withToken($tokenB)
        ->getJson(route('api.v1.messages.show', $mensaje->public_id))
        ->assertNotFound();
});

test('GET messages missing publicId returns sanitized 404 without model FQCN', function () {
    $tenant = Tenant::factory()->create();
    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo('mensajes.ver');
    $token = $client->createToken('client', ['mensajes.ver'])->plainTextToken;

    $missingPublicId = '01MISSINGMSGPUBLICID0000000';

    $response = $this->withToken($token)
        ->getJson(route('api.v1.messages.show', $missingPublicId));

    $response
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.')
        ->assertJsonMissingPath('exception');

    expect($response->getContent())->not->toContain('App\\Models');
});

test('GET messages returns sent status', function () {
    $tenant = Tenant::factory()->create();
    $mensaje = Mensaje::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo('mensajes.ver');
    $token = $client->createToken('client', ['mensajes.ver'])->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.messages.show', $mensaje->public_id))
        ->assertOk()
        ->assertJsonPath('status', 'sent');
});

test('tenant token can POST message to group recipient', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create(['tenant_id' => $tenant->id, 'module_key' => 'whatsapp', 'is_enabled' => true]);
    $instancia = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo(['mensajes.enviar', 'mensajes.ver']);
    $token = $client->createToken('client', ['mensajes.enviar', 'mensajes.ver'])->plainTextToken;

    $group = '120363012345678901@g.us';

    $response = $this->withToken($token)
        ->postJson(route('api.v1.messages.store'), [
            'recipient' => $group,
            'body' => 'Hola grupo',
            'instancePublicId' => $instancia->public_id,
        ], idempotencyHeaders());

    $response->assertAccepted()
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('recipient', $group);

    expect(Mensaje::query()->where('tenant_id', $tenant->id)->value('recipient'))->toBe($group);
    Bus::assertDispatched(TransactionalMessageJob::class);
});

test('POST messages rejects invalid group recipient with 422', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create(['tenant_id' => $tenant->id, 'module_key' => 'whatsapp', 'is_enabled' => true]);
    $instancia = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo('mensajes.enviar');
    $token = $client->createToken('client', ['mensajes.enviar'])->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.messages.store'), [
            'recipient' => '120363012345678901@c.us',
            'body' => 'No',
            'instancePublicId' => $instancia->public_id,
        ], idempotencyHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['recipient']);
});
