<?php

use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CoreSeeder::class);
});

test('admin user can authenticate', function () {
    $admin = User::where('email', 'admin@sistema.local')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->hasRole('admin'))->toBeTrue();
});

test('permission middleware blocks unauthorized user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('admin user can access dashboard route', function () {
    $admin = User::where('email', 'admin@sistema.local')->first();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk();
});

test('seeder creates core modules from vertical config', function () {
    expect(\App\Models\Core\Module::withoutGlobalScopes()->count())
        ->toBe(count(config('vertical.modules')));
});

test('seeder creates mensajes permissions for sanctum guard', function () {
    expect(\Spatie\Permission\Models\Permission::where('guard_name', 'sanctum')->whereIn('name', [
        'mensajes.enviar',
        'mensajes.ver',
    ])->count())->toBe(2);
});
