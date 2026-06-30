<?php

use App\Models\Core\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->tenant = Tenant::factory()->create();
});

test('platform service token can access tenant endpoints', function () {
    $user = createPlatformServiceUser();
    $token = $user->createToken('platform')->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.tenants.index'))
        ->assertOk();
});

test('tenant admin token cannot list tenants', function () {
    $user = User::factory()->forTenant($this->tenant)->create();
    $user->assignRole(Role::findByName('admin', 'web'));
    $token = $user->createToken('tenant-admin')->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.tenants.index'))
        ->assertForbidden();
});

test('tenant admin token can access health endpoint', function () {
    $user = User::factory()->forTenant($this->tenant)->create();
    $user->assignRole(Role::findByName('admin', 'web'));
    $token = $user->createToken('tenant-admin')->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.health'))
        ->assertOk()
        ->assertJsonStructure(['status', 'checks', 'timestamp', 'actingTenant']);
});

test('platform service health includes acting tenant when header is sent', function () {
    $user = createPlatformServiceUser();
    $token = $user->createToken('platform')->plainTextToken;

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $this->tenant->public_id)
        ->getJson(route('api.v1.health'))
        ->assertOk()
        ->assertJsonPath('actingTenant', $this->tenant->public_id);
});
