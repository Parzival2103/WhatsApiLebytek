<?php

use App\Models\Core\Module;
use App\Models\Core\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('platform service can provision tenant', function () {
    $token = platformServiceToken();

    $response = $this->withToken($token)
        ->postJson(route('api.v1.tenants.store'), [
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'externalRef' => 'waapi_org_1001',
        ], idempotencyHeaders());

    $response->assertCreated()
        ->assertJsonPath('publicId', fn ($value) => is_string($value) && $value !== '')
        ->assertJsonPath('slug', 'acme-corp')
        ->assertJsonPath('externalRef', 'waapi_org_1001')
        ->assertJsonPath('isActive', true);

    $this->assertDatabaseHas('core_tenants', [
        'slug' => 'acme-corp',
        'external_ref' => 'waapi_org_1001',
    ]);

    $tenant = Tenant::query()->where('slug', 'acme-corp')->firstOrFail();

    expect(Module::query()->where('tenant_id', $tenant->id)->count())->toBeGreaterThan(0);
});

test('tenant provisioning is idempotent by external ref', function () {
    $token = platformServiceToken();

    $payload = [
        'name' => 'Acme Corp',
        'slug' => 'acme-corp',
        'externalRef' => 'waapi_org_1001',
    ];

    $first = $this->withToken($token)
        ->postJson(route('api.v1.tenants.store'), $payload, idempotencyHeaders())
        ->assertCreated();

    $second = $this->withToken($token)
        ->postJson(route('api.v1.tenants.store'), $payload, idempotencyHeaders())
        ->assertOk();

    expect($second->json('publicId'))->toBe($first->json('publicId'));
    expect(Tenant::query()->where('external_ref', 'waapi_org_1001')->count())->toBe(1);
});

test('tenant user without platform permissions cannot provision tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $token = $user->createToken('tenant-user')->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.tenants.store'), [
            'name' => 'Blocked',
            'slug' => 'blocked',
        ], idempotencyHeaders())
        ->assertForbidden();
});

test('platform service can list and update tenants', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create(['name' => 'Before']);

    $this->withToken($token)
        ->getJson(route('api.v1.tenants.index'))
        ->assertOk()
        ->assertJsonStructure(['data' => [['publicId', 'name', 'slug']]]);

    $this->withToken($token)
        ->patchJson(route('api.v1.tenants.update', $tenant->public_id), [
            'name' => 'After',
            'isActive' => false,
        ], idempotencyHeaders())
        ->assertOk()
        ->assertJsonPath('name', 'After')
        ->assertJsonPath('isActive', false);
});

test('platform service can show tenant by public id', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create();

    $this->withToken($token)
        ->getJson(route('api.v1.tenants.show', $tenant->public_id))
        ->assertOk()
        ->assertJsonPath('publicId', $tenant->public_id);
});

test('platform PATCH rejects commercial fields that bypass activate-plan', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'name' => 'Before Commercial',
        'commercial_status' => 'demo',
        'plan_slug' => 'demo',
        'messages_monthly_limit' => 100,
    ]);

    $this->withToken($token)
        ->patchJson(route('api.v1.tenants.update', $tenant->public_id), [
            'planSlug' => 'empresa',
            'commercialStatus' => 'active',
            'messagesMonthlyLimit' => 999999,
        ], idempotencyHeaders())
        ->assertUnprocessable();

    $tenant->refresh();
    expect($tenant->plan_slug)->toBe('demo')
        ->and($tenant->commercial_status)->toBe('demo')
        ->and($tenant->messages_monthly_limit)->toBe(100);
});

test('platform PATCH still updates name and isActive', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create(['name' => 'Rename Me', 'is_active' => true]);

    $this->withToken($token)
        ->patchJson(route('api.v1.tenants.update', $tenant->public_id), [
            'name' => 'Renamed',
            'isActive' => false,
        ], idempotencyHeaders())
        ->assertOk()
        ->assertJsonPath('name', 'Renamed')
        ->assertJsonPath('isActive', false);
});
