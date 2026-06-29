<?php

use App\Models\Core\Tenant;
use App\Services\ConfigurationService;
use App\Support\Config\ConfigurationKey;
use Database\Seeders\CoreSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CoreSeeder::class);
});

test('manifest returns configured app name', function () {
    $tenant = Tenant::where('slug', 'default')->first();
    $config = app(ConfigurationService::class);

    $config->set(ConfigurationKey::AppName, 'Mi App PWA', \App\Models\User::where('email', 'admin@sistema.local')->first());

    $response = $this->get(route('pwa.manifest'));

    $response->assertOk()
        ->assertJsonPath('name', 'Mi App PWA')
        ->assertJsonPath('short_name', 'Mi App PWA')
        ->assertHeader('Cache-Control');
});

test('public landing renders', function () {
    $this->get(route('public.index'))
        ->assertOk();
});

test('favicon endpoint returns png', function () {
    $this->get(route('pwa.favicon'))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});
