<?php

use App\Models\User;
use App\Services\AdminMenuService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CoreSeeder::class);
    $this->menuService = app(AdminMenuService::class);
});

test('admin user sees full menu tree', function () {
    $admin = User::where('email', 'admin@sistema.local')->first();

    $menu = $this->menuService->forUser($admin);

    expect($menu)->not->toBeEmpty()
        ->and(collect($menu)->pluck('label'))->toContain('Dashboard', 'Configuración');
});

test('user with subset of permissions sees filtered menu', function () {
    $tenant = \App\Models\Core\Tenant::where('slug', 'default')->first();

    $viewerRole = Role::create(['name' => 'viewer', 'guard_name' => 'web']);
    $viewerRole->givePermissionTo(Permission::findByName('dashboard.ver', 'web'));

    $user = User::factory()->forTenant($tenant)->create();
    $user->assignRole($viewerRole);

    $menu = $this->menuService->forUser($user);

    $labels = collect($menu)->pluck('label')->all();

    expect($labels)->toContain('Dashboard')
        ->and($labels)->not->toContain('Configuración');
});

test('menu is cached per role', function () {
    $admin = User::where('email', 'admin@sistema.local')->first();

    $this->menuService->forUser($admin);

    $roleKey = $admin->getRoleNames()->sort()->implode('|');
    $tenantId = $admin->tenant_id ?? 'platform';

    expect(\Illuminate\Support\Facades\Cache::has("admin_menu:{$tenantId}:{$roleKey}"))->toBeTrue();
});
