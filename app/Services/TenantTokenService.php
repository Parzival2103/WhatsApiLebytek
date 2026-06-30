<?php

namespace App\Services;

use App\Models\Core\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

class TenantTokenService
{
    /**
     * @param  list<string>|null  $abilities
     */
    public function issue(Tenant $tenant, string $name, ?array $abilities = null): NewAccessToken
    {
        $abilities ??= ['instancias.ver'];

        $clientUser = $this->findOrCreateClientUser($tenant);

        return $clientUser->createToken($name, $abilities);
    }

    private function findOrCreateClientUser(Tenant $tenant): User
    {
        $email = "api-client+{$tenant->slug}@tenants.lebytek.internal";

        return User::query()->firstOrCreate(
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
    }
}
