<?php

use App\Models\Core\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->tenant = Tenant::factory()->create();
});

test('sanctum token can access health endpoint with permission', function () {
    $user = User::factory()->forTenant($this->tenant)->create();
    $user->assignRole(Role::findByName('admin', 'web'));

    $token = $user->createToken('api-test')->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.health'))
        ->assertOk()
        ->assertJsonStructure(['status', 'checks', 'timestamp']);
});

test('sanctum request without permission is forbidden', function () {
    $user = User::factory()->forTenant($this->tenant)->create();
    $token = $user->createToken('api-test')->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.health'))
        ->assertForbidden();
});

test('unauthenticated api request is unauthorized', function () {
    $this->getJson(route('api.v1.health'))
        ->assertUnauthorized();
});

test('api route without explicit permission middleware is denied', function () {
    $user = User::factory()->forTenant($this->tenant)->create();
    $user->assignRole(Role::findByName('admin', 'web'));
    $token = $user->createToken('api-test')->plainTextToken;

    \Illuminate\Support\Facades\Route::middleware(['api', 'auth:sanctum', 'ensure.api.permission'])
        ->get('/api/v1/test-unprotected', fn () => response()->json(['ok' => true]));

    $this->withToken($token)
        ->getJson('/api/v1/test-unprotected')
        ->assertForbidden();
});
