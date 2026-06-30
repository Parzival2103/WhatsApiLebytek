<?php

namespace Database\Seeders;

use App\Models\Core\MenuItem;
use App\Models\Core\Module;
use App\Models\Core\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class CoreSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'default'],
            [
                'name' => 'Tenant por defecto',
                'is_active' => true,
            ],
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@sistema.local'],
            [
                'name' => 'Administrador',
                'password' => Hash::make('password'),
                'tenant_id' => $tenant->id,
                'is_platform_admin' => true,
                'must_change_password' => false,
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole(Role::findByName('admin', 'web'));

        $this->seedModulesAndMenu($tenant);
    }

    public function seedModulesAndMenu(Tenant $tenant): void
    {
        foreach (config('vertical.modules', []) as $moduleKey => $meta) {
            Module::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'module_key' => $moduleKey,
                ],
                [
                    'is_enabled' => $moduleKey === 'core',
                ],
            );
        }

        $menuItems = [
            [
                'label' => 'Dashboard',
                'route_name' => 'admin.dashboard',
                'permission' => 'dashboard.ver',
                'icon' => 'layout-dashboard',
                'sort_order' => 10,
            ],
            [
                'label' => 'Configuración',
                'route_name' => null,
                'permission' => 'configuracion.gestionar',
                'icon' => 'settings',
                'sort_order' => 20,
                'children' => [
                    [
                        'label' => 'Layout',
                        'route_name' => 'admin.config.layout',
                        'permission' => 'configuracion.gestionar',
                        'sort_order' => 1,
                    ],
                    [
                        'label' => 'Tema',
                        'route_name' => 'admin.config.theme',
                        'permission' => 'configuracion.gestionar',
                        'sort_order' => 2,
                    ],
                    [
                        'label' => 'Branding y PWA',
                        'route_name' => 'admin.config.branding',
                        'permission' => 'configuracion.gestionar',
                        'sort_order' => 3,
                    ],
                ],
            ],
        ];

        foreach ($menuItems as $item) {
            $this->seedMenuItem($item);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function seedMenuItem(array $item, ?int $parentId = null): void
    {
        $children = $item['children'] ?? [];
        unset($item['children']);

        $menuItem = MenuItem::firstOrCreate(
            [
                'tenant_id' => null,
                'label' => $item['label'],
                'route_name' => $item['route_name'],
            ],
            [
                'parent_id' => $parentId,
                'permission' => $item['permission'],
                'icon' => $item['icon'] ?? null,
                'sort_order' => $item['sort_order'] ?? 0,
                'is_active' => true,
            ],
        );

        foreach ($children as $child) {
            $this->seedMenuItem($child, $menuItem->id);
        }
    }
}
