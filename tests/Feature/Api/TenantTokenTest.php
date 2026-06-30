<?php

use App\Models\Core\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('platform service can issue tenant token', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create(['slug' => 'acme-demo']);

    $response = $this->withToken($token)
        ->postJson(route('api.v1.tenants.tokens.store', $tenant->public_id), [
            'name' => 'cliente-acme',
            'abilities' => ['instancias.ver'],
        ], idempotencyHeaders());

    $response->assertCreated()
        ->assertJsonPath('name', 'cliente-acme')
        ->assertJsonPath('abilities', ['instancias.ver'])
        ->assertJsonStructure(['publicId', 'token', 'createdAt']);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
});

test('tenant user cannot issue tenant tokens', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $userToken = $user->createToken('tenant-user')->plainTextToken;

    $this->withToken($userToken)
        ->postJson(route('api.v1.tenants.tokens.store', $tenant->public_id), [
            'name' => 'blocked',
        ], idempotencyHeaders())
        ->assertForbidden();
});
