<?php

use App\Models\Core\Module;
use App\Models\Core\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('x tenant id header scopes tenant context for platform service', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    Module::factory()->create([
        'tenant_id' => $tenantA->id,
        'module_key' => 'scope-a',
        'is_enabled' => true,
    ]);

    Module::factory()->create([
        'tenant_id' => $tenantB->id,
        'module_key' => 'scope-b',
        'is_enabled' => true,
    ]);

    Route::middleware(['api', 'auth:sanctum', 'ensure.api.permission', 'permission:api.health'])
        ->get('/api/v1/test-tenant-scope', function () {
            return response()->json([
                'count' => Module::query()->count(),
                'keys' => Module::query()->pluck('module_key')->all(),
            ]);
        });

    $user = createPlatformServiceUser();
    $token = $user->createToken('scope-test')->plainTextToken;

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenantA->public_id)
        ->getJson('/api/v1/test-tenant-scope')
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('keys', ['scope-a']);
});

test('invalid x tenant id header returns not found', function () {
    $user = createPlatformServiceUser();
    $token = $user->createToken('scope-test')->plainTextToken;

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', (string) \Illuminate\Support\Str::ulid())
        ->getJson(route('api.v1.health'))
        ->assertNotFound();
});

test('non platform user ignores x tenant id header', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'owned',
        'is_enabled' => true,
    ]);

    Module::factory()->create([
        'tenant_id' => $otherTenant->id,
        'module_key' => 'foreign',
        'is_enabled' => true,
    ]);

    Route::middleware(['api', 'auth:sanctum', 'ensure.api.permission', 'permission:api.health'])
        ->get('/api/v1/test-tenant-user-scope', function () {
            return response()->json([
                'count' => Module::query()->count(),
            ]);
        });

    $user = User::factory()->forTenant($tenant)->create();
    $user->givePermissionTo('api.health');
    $token = $user->createToken('tenant-user')->plainTextToken;

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $otherTenant->public_id)
        ->getJson('/api/v1/test-tenant-user-scope')
        ->assertOk()
        ->assertJsonPath('count', 1);
});
