<?php

use App\Models\Core\Tenant;
use App\Models\User;
use App\Services\ConfigurationService;
use App\Support\Config\ConfigurationKey;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    TenantContext::set($this->tenant->id);
    $this->service = app(ConfigurationService::class);
    $this->user = User::factory()->forTenant($this->tenant)->create();
});

test('configuration set and get roundtrip', function () {
    $this->service->set(ConfigurationKey::LayoutMode, 'top', $this->user);

    expect($this->service->get(ConfigurationKey::LayoutMode, $this->tenant->id))->toBe('top');
});

test('configuration returns default when not set', function () {
    expect($this->service->get(ConfigurationKey::LayoutMode, $this->tenant->id))->toBe('side');
});

test('configuration is cached after first read', function () {
    $this->service->set(ConfigurationKey::AppName, 'Cached App', $this->user);

    $this->service->get(ConfigurationKey::AppName, $this->tenant->id);

    \App\Models\Cfg\Configuracion::withoutGlobalScopes()
        ->where('tenant_id', $this->tenant->id)
        ->where('key', ConfigurationKey::AppName->value)
        ->delete();

    expect($this->service->get(ConfigurationKey::AppName, $this->tenant->id))->toBe('Cached App');

    Cache::forget("cfg:{$this->tenant->id}:".ConfigurationKey::AppName->value);

    expect($this->service->get(ConfigurationKey::AppName, $this->tenant->id))
        ->toBe(config('app.name', 'Lebytek'));
});

test('cache is invalidated on set', function () {
    $cacheKey = "cfg:{$this->tenant->id}:".ConfigurationKey::LayoutMode->value;

    $this->service->get(ConfigurationKey::LayoutMode, $this->tenant->id);

    expect(Cache::has($cacheKey))->toBeTrue();

    $this->service->set(ConfigurationKey::LayoutMode, 'top', $this->user);

    expect(Cache::has($cacheKey))->toBeFalse()
        ->and($this->service->get(ConfigurationKey::LayoutMode, $this->tenant->id))->toBe('top');
});
