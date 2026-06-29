<?php

/**
 * Core Sanctum auth coverage (see also tests/Feature/Api/SanctumAuthTest.php).
 */

use App\Models\Core\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->tenant = Tenant::factory()->create();
});

test('sanctum bearer token authenticates api v1 health endpoint', function () {
    $user = User::factory()->forTenant($this->tenant)->create();
    $user->assignRole(Role::findByName('admin', 'web'));

    $token = $user->createToken('ci-test')->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.health'))
        ->assertOk()
        ->assertJsonStructure(['status', 'checks', 'timestamp']);
});
