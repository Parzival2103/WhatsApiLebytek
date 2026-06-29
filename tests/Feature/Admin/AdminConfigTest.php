<?php

use App\Models\User;
use App\Support\Config\ConfigurationKey;
use Database\Seeders\CoreSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CoreSeeder::class);
    $this->admin = User::where('email', 'admin@sistema.local')->first();
});

test('admin can login via admin login route', function () {
    $this->post(route('admin.login.store'), [
        'email' => 'admin@sistema.local',
        'password' => 'password',
    ])->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($this->admin);
});

test('admin can update layout configuration', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.config.layout.update'), [
            'layoutMode' => 'top',
        ])
        ->assertRedirect();

    expect(app(\App\Services\ConfigurationService::class)->get(ConfigurationKey::LayoutMode))
        ->toBe('top');
});

test('admin can update theme configuration', function () {
    $colors = [
        'primary' => '#111111',
        'secondary' => '#222222',
        'accent' => '#333333',
        'background' => '#ffffff',
        'foreground' => '#000000',
    ];

    $this->actingAs($this->admin)
        ->put(route('admin.config.theme.update'), [
            'themeColors' => $colors,
        ])
        ->assertRedirect();

    expect(app(\App\Services\ConfigurationService::class)->get(ConfigurationKey::ThemeColors))
        ->toBe($colors);
});

test('admin can update branding configuration', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.config.branding.update'), [
            'appName' => 'Mi Plataforma',
            'pwaThemeColor' => '#112233',
            'pwaBackgroundColor' => '#ffffff',
        ])
        ->assertRedirect();

    $config = app(\App\Services\ConfigurationService::class);

    expect($config->get(ConfigurationKey::AppName))->toBe('Mi Plataforma')
        ->and($config->get(ConfigurationKey::PwaThemeColor))->toBe('#112233');
});
