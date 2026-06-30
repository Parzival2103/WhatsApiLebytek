<?php

use App\Models\Core\Tenant;
use App\Models\User;
use App\Services\ConfigurationService;
use App\Support\Config\ConfigurationKey;
use Database\Seeders\CoreSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CoreSeeder::class);
});

test('manifest cache is invalidated when branding config changes', function () {
    $tenant = Tenant::where('slug', 'default')->first();
    $config = app(ConfigurationService::class);
    $admin = User::where('email', 'admin@sistema.local')->first();

    $this->get(route('pwa.manifest'))->assertOk();
    expect(Cache::has('pwa.manifest:'.($tenant->id)))->toBeTrue();

    $config->set(ConfigurationKey::AppName, 'Nueva App', $admin);

    expect(Cache::has('pwa.manifest:'.($tenant->id)))->toBeFalse();
});

test('public snapshot includes branding urls', function () {
    $snapshot = app(ConfigurationService::class)->getPublicSnapshot();

    expect($snapshot)->toHaveKeys(['logoUrl', 'faviconUrl', 'pwaIconUrl'])
        ->and($snapshot['faviconUrl'])->toContain('favicon');
});

test('admin menu cache version bumps on layout change', function () {
    $admin = User::where('email', 'admin@sistema.local')->first();
    $menu = app(\App\Services\AdminMenuService::class);
    $config = app(ConfigurationService::class);

    $menu->forUser($admin);
    $tenantId = $admin->tenant_id ?? 'platform';
    $versionBefore = (int) Cache::get('admin_menu_version:'.$tenantId, 0);

    $config->set(ConfigurationKey::LayoutMode, 'top', $admin);

    expect((int) Cache::get('admin_menu_version:'.$tenantId, 0))->toBe($versionBefore + 1);
});
