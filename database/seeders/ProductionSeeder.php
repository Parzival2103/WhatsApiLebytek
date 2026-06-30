<?php

namespace Database\Seeders;

use App\Models\Core\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('nucleo.admin_initial_password');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('ADMIN_INITIAL_PASSWORD must be set in .env for production seeding.');
        }

        $this->call(RolesAndPermissionsSeeder::class);

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'default'],
            [
                'name' => 'Tenant por defecto',
                'is_active' => true,
            ],
        );

        $email = config('nucleo.admin_initial_email');

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => config('nucleo.admin_initial_name'),
                'password' => Hash::make($password),
                'tenant_id' => $tenant->id,
                'is_platform_admin' => true,
                'must_change_password' => true,
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole(Role::findByName('admin', 'web'));

        (new CoreSeeder)->seedModulesAndMenu($tenant);
    }
}
