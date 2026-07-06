<?php

use App\Models\Integration\Mensaje;
use App\Models\Core\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('tenant token can GET usage summary', function () {
    $tenant = Tenant::factory()->create();
    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo('mensajes.ver');
    $token = $client->createToken('client', ['mensajes.ver'])->plainTextToken;

    Mensaje::factory()->count(2)->create([
        'tenant_id' => $client->tenant_id,
        'direction' => 'outbound',
        'status' => 'sent',
    ]);
    Mensaje::factory()->create([
        'tenant_id' => $client->tenant_id,
        'direction' => 'outbound',
        'status' => 'failed',
    ]);

    $this->withToken($token)
        ->getJson(route('api.v1.usage'))
        ->assertOk()
        ->assertJsonPath('messagesSent', 3)
        ->assertJsonPath('messagesReceived', 0)
        ->assertJsonPath('messagesSentByStatus.sent', 2)
        ->assertJsonPath('messagesSentByStatus.failed', 1);
});

test('tenant cannot read another tenant usage', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $clientA = User::factory()->forTenant($tenantA)->create();
    $clientB = User::factory()->forTenant($tenantB)->create();
    $clientB->givePermissionTo('mensajes.ver');

    Mensaje::factory()->create(['tenant_id' => $clientA->tenant_id, 'direction' => 'outbound']);

    $tokenB = $clientB->createToken('client', ['mensajes.ver'])->plainTextToken;

    $this->withToken($tokenB)
        ->getJson(route('api.v1.usage'))
        ->assertOk()
        ->assertJsonPath('messagesSent', 0);
});
