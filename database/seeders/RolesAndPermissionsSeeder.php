<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = config('permissions.nucleo', []);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(Permission::where('guard_name', 'web')->get());

        $apiAdminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'sanctum']);
        $apiAdminRole->syncPermissions(Permission::where('guard_name', 'sanctum')->get());

        $platformPermissions = config('permissions.platform_service', []);

        foreach ($platformPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }

        $platformServiceRole = Role::firstOrCreate(['name' => 'platform-service', 'guard_name' => 'sanctum']);
        $platformServiceRole->syncPermissions(
            Permission::where('guard_name', 'sanctum')
                ->whereIn('name', $platformPermissions)
                ->get()
        );
    }
}
