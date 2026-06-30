<?php

use App\Jobs\ProvisionGreenInstanceJob;
use App\Models\Core\Module;
use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Bus::fake([ProvisionGreenInstanceJob::class]);
});

test('platform service can create instance with acting tenant header', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create();
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);

    $response = $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Demo Acme',
            'externalRef' => 'lead_42',
            'purpose' => 'demo',
        ], idempotencyHeaders());

    $response->assertAccepted()
        ->assertJsonPath('status', 'provisioning')
        ->assertJsonPath('label', 'Demo Acme');

    Bus::assertDispatched(ProvisionGreenInstanceJob::class);

    $this->assertDatabaseHas('int_instancias', [
        'tenant_id' => $tenant->id,
        'external_ref' => 'lead_42',
        'status' => 'provisioning',
    ]);
});

test('instance creation is idempotent by external ref', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create();
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);

    $payload = [
        'label' => 'Demo Acme',
        'externalRef' => 'lead_42',
    ];

    $headers = array_merge(idempotencyHeaders(), ['X-Tenant-Id' => $tenant->public_id]);

    $first = $this->withToken($token)
        ->postJson(route('api.v1.instances.store'), $payload, $headers)
        ->assertAccepted();

    $second = $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), $payload, idempotencyHeaders())
        ->assertOk();

    expect($second->json('publicId'))->toBe($first->json('publicId'));
    expect(Instancia::query()->where('external_ref', 'lead_42')->count())->toBe(1);
    Bus::assertDispatchedTimes(ProvisionGreenInstanceJob::class, 1);
});

test('tenant token can read own instances but not create', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    $instancia = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'waiting_qr',
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo('instancias.ver');
    $clientToken = $client->createToken('client', ['instancias.ver'])->plainTextToken;

    $this->withToken($clientToken)
        ->getJson(route('api.v1.instances.show', $instancia->public_id))
        ->assertOk()
        ->assertJsonPath('publicId', $instancia->public_id);

    $this->withToken($clientToken)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Blocked',
        ], idempotencyHeaders())
        ->assertForbidden();
});

test('instance routes require whatsapp module enabled', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create();
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => false,
    ]);

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Demo',
        ], idempotencyHeaders())
        ->assertForbidden();
});
