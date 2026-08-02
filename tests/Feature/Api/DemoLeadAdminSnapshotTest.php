<?php

use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('POST admin demo-leads-snapshot returns tenant activity and synced instance state', function () {
    $tenant = Tenant::factory()->create([
        'last_api_activity_at' => now()->subHour(),
    ]);
    $instancia = Instancia::factory()->authorized()->create([
        'tenant_id' => $tenant->id,
        'id_instance' => '1109997771',
        'api_token_instance' => 'green-secret',
    ]);

    Http::fake([
        '*/waInstance1109997771/getStateInstance/*' => Http::response([
            'stateInstance' => 'blocked',
        ], 200),
    ]);

    $token = platformServiceToken();

    $this->withToken($token)
        ->postJson(route('api.v1.admin.demo-leads-snapshot'), [
            'items' => [[
                'tenantPublicId' => $tenant->public_id,
                'instancePublicId' => $instancia->public_id,
            ]],
        ], idempotencyHeaders())
        ->assertOk()
        ->assertJsonPath("items.{$tenant->public_id}.lastApiActivityAt", fn ($value) => $value !== null)
        ->assertJsonPath("items.{$tenant->public_id}.instance.status", 'authorized')
        ->assertJsonPath("items.{$tenant->public_id}.instance.greenState", 'blocked');

    expect($instancia->refresh()->green_state)->toBe('blocked');
});

test('POST admin demo-leads-snapshot omits unknown tenants without failing', function () {
    $token = platformServiceToken();

    $this->withToken($token)
        ->postJson(route('api.v1.admin.demo-leads-snapshot'), [
            'items' => [[
                'tenantPublicId' => (string) \Illuminate\Support\Str::ulid(),
                'instancePublicId' => null,
            ]],
        ], idempotencyHeaders())
        ->assertOk()
        ->assertJsonPath('items', []);
});

test('POST admin demo-leads-snapshot rejects non platform users', function () {
    $tenant = Tenant::factory()->create();
    $client = \App\Models\User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo('tenants.ver');
    $token = $client->createToken('client', ['tenants.ver'])->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.admin.demo-leads-snapshot'), [
            'items' => [[
                'tenantPublicId' => $tenant->public_id,
            ]],
        ], idempotencyHeaders())
        ->assertForbidden();
});
