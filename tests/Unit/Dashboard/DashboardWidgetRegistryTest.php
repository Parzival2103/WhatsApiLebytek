<?php

use App\Models\Core\Tenant;
use App\Models\User;
use App\Services\DashboardWidgetRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->registry = app(DashboardWidgetRegistry::class);
});

test('registry returns widgets filtered by user permissions', function () {
    $tenant = Tenant::factory()->create();

    $viewerRole = Role::create(['name' => 'viewer', 'guard_name' => 'web']);
    $viewerRole->givePermissionTo(Permission::findByName('dashboard.ver', 'web'));

    $user = User::factory()->forTenant($tenant)->create();
    $user->assignRole($viewerRole);

    $widgets = $this->registry->forUser($user);

    expect($widgets)->toHaveCount(1)
        ->and($widgets[0]['key'])->toBe('welcome');
});

test('admin user sees all registered widgets', function () {
    $tenant = Tenant::factory()->create();
    $adminRole = Role::findByName('admin', 'web');

    $user = User::factory()->forTenant($tenant)->create();
    $user->assignRole($adminRole);

    $widgets = $this->registry->forUser($user);

    expect($widgets)->toHaveCount(2)
        ->and(collect($widgets)->pluck('key')->all())->toContain('welcome', 'system-status');
});

test('widget contract exposes key permission component and data', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $user->givePermissionTo('dashboard.ver');

    $widget = $this->registry->forUser($user)[0];

    expect($widget)->toHaveKeys(['key', 'permission', 'component', 'data']);
});
