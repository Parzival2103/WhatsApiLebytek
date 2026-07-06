<?php

namespace App\Services;

use App\Models\Core\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Spatie\Permission\Models\Permission;

class TenantTokenService
{
    /**
     * @param  list<string>|null  $abilities
     */
    public function issue(Tenant $tenant, string $name, ?array $abilities = null): NewAccessToken
    {
        $abilities ??= config('permissions.demo_client_abilities', ['instancias.ver']);

        $clientUser = $this->findOrCreateClientUser($tenant);
        $this->syncClientPermissions($clientUser, $abilities);

        return $clientUser->createToken($name, $abilities);
    }

    /**
     * Spatie permission middleware checks user permissions, not Sanctum token abilities.
     *
     * @param  list<string>  $abilities
     */
    private function syncClientPermissions(User $clientUser, array $abilities): void
    {
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $abilities)
            ->get();

        $clientUser->syncPermissions($permissions);
    }

    private function findOrCreateClientUser(Tenant $tenant): User
    {
        $email = "api-client+{$tenant->slug}@tenants.lebytek.internal";

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => "API Client ({$tenant->name})",
                'password' => Hash::make(Str::password(32)),
                'tenant_id' => $tenant->id,
                'is_platform_admin' => false,
                'must_change_password' => false,
                'email_verified_at' => now(),
            ],
        );

        if ($user->tenant_id !== $tenant->id || $user->is_platform_admin) {
            $user->forceFill([
                'tenant_id' => $tenant->id,
                'is_platform_admin' => false,
            ])->save();
        }

        return $user;
    }
}
