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

test('platform service issues demo client abilities when abilities omitted', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create(['slug' => 'default-abilities-demo']);
    $expectedAbilities = config('permissions.demo_client_abilities');

    $response = $this->withToken($token)
        ->postJson(route('api.v1.tenants.tokens.store', $tenant->public_id), [
            'name' => 'cliente-default',
        ], idempotencyHeaders());

    $response->assertCreated()
        ->assertJsonPath('name', 'cliente-default')
        ->assertJsonPath('abilities', $expectedAbilities);

    expect($expectedAbilities)->toContain('cuenta.ver');

    $clientUser = User::query()
        ->where('email', 'api-client+default-abilities-demo@tenants.lebytek.internal')
        ->first();

    expect($clientUser)->not->toBeNull();
    expect($clientUser->can('cuenta.ver'))->toBeTrue();
    expect($clientUser->can('mensajes.enviar'))->toBeTrue();
    expect($clientUser->can('mensajes.ver'))->toBeTrue();
    expect($clientUser->can('instancias.ver'))->toBeTrue();
});

test('default issued tenant token can access account status', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'slug' => 'account-status-demo',
        'commercial_status' => 'demo',
        'plan_slug' => 'demo',
        'plan_name' => 'Demo',
        'demo_started_at' => now()->subDays(3),
        'demo_expires_at' => now()->addDays(27),
        'messages_monthly_limit' => 50,
    ]);

    $issueResponse = $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.tokens.store', $tenant->public_id), [
            'name' => 'cliente-account-status',
        ], idempotencyHeaders());

    $issueResponse->assertCreated();

    $clientToken = $issueResponse->json('token');

    $this->app['auth']->forgetGuards();

    $this->withToken($clientToken)
        ->postJson(route('api.v1.account.status'), [])
        ->assertOk()
        ->assertJsonPath('commercialStatus', 'demo')
        ->assertJsonPath('plan.slug', 'demo');
});

test('platform service can issue tenant token with mensajes abilities', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create(['slug' => 'mensajes-demo']);
    $abilities = ['instancias.ver', 'mensajes.enviar', 'mensajes.ver'];

    $response = $this->withToken($token)
        ->postJson(route('api.v1.tenants.tokens.store', $tenant->public_id), [
            'name' => 'cliente-mensajes',
            'abilities' => $abilities,
        ], idempotencyHeaders());

    $response->assertCreated()
        ->assertJsonPath('name', 'cliente-mensajes')
        ->assertJsonPath('abilities', $abilities);
});

test('platform service rejects invalid tenant token abilities', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create(['slug' => 'invalid-abilities']);

    $this->withToken($token)
        ->postJson(route('api.v1.tenants.tokens.store', $tenant->public_id), [
            'name' => 'cliente-invalid',
            'abilities' => ['instancias.ver', 'campanias.enviar'],
        ], idempotencyHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['abilities.1']);
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
