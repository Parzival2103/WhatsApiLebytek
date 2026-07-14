<?php

use App\Models\Core\Tenant;
use App\Models\User;
use App\Services\TenantTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(TenantTokenService::class);
});

test('issue syncs spatie permissions for tenant api client user', function () {
    $tenant = Tenant::factory()->create(['slug' => 'perm-sync-demo']);
    $abilities = ['instancias.ver', 'mensajes.enviar', 'mensajes.ver'];

    $accessToken = $this->service->issue($tenant, 'cliente-perm-sync', $abilities);
    $clientUser = User::query()
        ->where('email', 'api-client+perm-sync-demo@tenants.lebytek.internal')
        ->first();

    expect($clientUser)->not->toBeNull();
    expect($clientUser->tenant_id)->toBe($tenant->id);
    expect($clientUser->isPlatformAdmin())->toBeFalse();
    expect($clientUser->can('instancias.ver'))->toBeTrue();
    expect($clientUser->can('mensajes.enviar'))->toBeTrue();
    expect($clientUser->can('mensajes.ver'))->toBeTrue();
    expect($accessToken->accessToken->abilities)->toBe($abilities);
});

test('issue repairs existing api client user missing tenant binding', function () {
    $tenant = Tenant::factory()->create(['slug' => 'repair-demo']);
    $email = 'api-client+repair-demo@tenants.lebytek.internal';

    User::factory()->create([
        'email' => $email,
        'tenant_id' => null,
        'is_platform_admin' => false,
    ]);

    $this->service->issue($tenant, 'cliente-repair', ['instancias.ver']);

    $clientUser = User::query()->where('email', $email)->first();
    expect($clientUser->tenant_id)->toBe($tenant->id);
    expect($clientUser->isPlatformAdmin())->toBeFalse();
    expect($clientUser->can('instancias.ver'))->toBeTrue();
});

test('revokeClientTokens deletes sanctum tokens for api client user', function () {
    $tenant = Tenant::factory()->create(['slug' => 'revoke-me']);
    $service = app(TenantTokenService::class);

    $issued = $service->issue($tenant, 'demo-token');
    $tokenId = $issued->accessToken->getKey();

    expect($service->revokeClientTokens($tenant))->toBe(1);
    expect(\Laravel\Sanctum\PersonalAccessToken::query()->find($tokenId))->toBeNull();
});
