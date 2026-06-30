<?php

use App\Models\Core\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->tenant = Tenant::factory()->create();
});

test('laravel up endpoint responds', function () {
    $this->get('/up')->assertOk();
});

test('api v1 health returns database and redis checks', function () {
    $user = User::factory()->forTenant($this->tenant)->create();
    $user->assignRole(Role::findByName('admin', 'web'));
    $token = $user->createToken('health-test')->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.health'))
        ->assertOk()
        ->assertJsonStructure([
            'status',
            'checks' => ['database', 'redis'],
            'timestamp',
            'actingTenant',
        ]);
});
