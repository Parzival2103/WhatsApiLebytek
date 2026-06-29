<?php

use App\Models\Cfg\Configuracion;
use App\Models\Core\Tenant;
use App\Support\TenantContext;

test('tenant user cannot read another tenant configuration records', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    Configuracion::factory()->create([
        'tenant_id' => $tenantA->id,
        'key' => 'layout.mode',
        'value' => ['mode' => 'top'],
    ]);

    Configuracion::factory()->create([
        'tenant_id' => $tenantB->id,
        'key' => 'layout.mode',
        'value' => ['mode' => 'side'],
    ]);

    TenantContext::set($tenantA->id);

    $visible = Configuracion::query()->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->tenant_id)->toBe($tenantA->id);
});

test('platform admin bypasses tenant scope', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    Configuracion::factory()->create(['tenant_id' => $tenantA->id, 'key' => 'a', 'value' => []]);
    Configuracion::factory()->create(['tenant_id' => $tenantB->id, 'key' => 'b', 'value' => []]);

    TenantContext::set(null, bypassScope: true);

    expect(Configuracion::query()->count())->toBe(2);
});

test('creating record auto assigns tenant_id from context', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::set($tenant->id);

    $config = Configuracion::create([
        'key' => 'app.name',
        'value' => ['name' => 'Test App'],
    ]);

    expect($config->tenant_id)->toBe($tenant->id);
});
