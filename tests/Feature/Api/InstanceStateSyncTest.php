<?php

use App\Models\Core\Module;
use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Models\User;
use App\Services\GreenApi\InstanceStateSyncService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('refreshFromGreen downgrades authorized to waiting_qr when Green reports notAuthorized', function () {
    $instancia = Instancia::factory()->authorized()->create([
        'id_instance' => '1109998887',
        'api_token_instance' => 'green-secret',
    ]);

    Http::fake([
        '*/waInstance1109998887/getStateInstance/*' => Http::response([
            'stateInstance' => 'notAuthorized',
        ], 200),
    ]);

    $fresh = app(InstanceStateSyncService::class)->refreshFromGreen($instancia);

    expect($fresh->status)->toBe('waiting_qr')
        ->and($fresh->green_state)->toBe('notAuthorized')
        ->and($fresh->authorized_at)->toBeNull();
});

test('GET instance syncs stale authorized after Green logout', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    $instancia = Instancia::factory()->authorized()->create([
        'tenant_id' => $tenant->id,
        'id_instance' => '1109998888',
        'api_token_instance' => 'green-secret',
    ]);

    Http::fake([
        '*/waInstance1109998888/getStateInstance/*' => Http::response([
            'stateInstance' => 'notAuthorized',
        ], 200),
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo('instancias.ver');
    $token = $client->createToken('client', ['instancias.ver'])->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.instances.show', $instancia->public_id))
        ->assertOk()
        ->assertJsonPath('status', 'waiting_qr')
        ->assertJsonPath('greenState', 'notAuthorized');

    expect($instancia->refresh()->status)->toBe('waiting_qr');
});

test('POST messages returns 409 when Green reports notAuthorized despite DB authorized', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    $instancia = Instancia::factory()->authorized()->create([
        'tenant_id' => $tenant->id,
        'id_instance' => '1109998889',
        'api_token_instance' => 'green-secret',
    ]);

    Http::fake([
        '*/waInstance1109998889/getStateInstance/*' => Http::response([
            'stateInstance' => 'notAuthorized',
        ], 200),
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo('mensajes.enviar');
    $token = $client->createToken('client', ['mensajes.enviar'])->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.messages.store'), [
            'recipient' => '5215512345678',
            'body' => 'Should not send',
            'instancePublicId' => $instancia->public_id,
        ], idempotencyHeaders())
        ->assertStatus(409);

    expect($instancia->refresh()->status)->toBe('waiting_qr');
});
