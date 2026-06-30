<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class WaapiServiceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $email = config('nucleo.waapi_service_email');
        $platformPermissions = config('permissions.platform_service', []);

        $serviceUser = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => config('nucleo.waapi_service_name'),
                'password' => Hash::make(Str::password(32)),
                'tenant_id' => null,
                'is_platform_admin' => true,
                'must_change_password' => false,
                'email_verified_at' => now(),
            ],
        );

        $serviceUser->syncPermissions(
            Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $platformPermissions)
                ->get()
        );

        $serviceUser->syncRoles([
            Role::findByName('platform-service', 'sanctum'),
        ]);
    }
}
