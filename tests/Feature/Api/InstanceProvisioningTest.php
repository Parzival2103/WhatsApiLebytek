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

test('instance creation retries failed instance by external ref', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create();
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);

    $failed = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'external_ref' => 'lead_42',
        'status' => 'failed',
        'id_instance' => '770022692540',
        'api_token_instance' => 'stale-token',
        'last_error' => 'Green delete failed: Partner deleteInstanceAccount failed: Not found',
    ]);

    $response = $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Demo Acme',
            'externalRef' => 'lead_42',
            'purpose' => 'demo',
        ], idempotencyHeaders())
        ->assertAccepted()
        ->assertJsonPath('publicId', $failed->public_id)
        ->assertJsonPath('status', 'provisioning');

    Bus::assertDispatched(ProvisionGreenInstanceJob::class);

    $failed->refresh();
    expect($failed->status)->toBe('provisioning')
        ->and($failed->id_instance)->toBeNull()
        ->and($failed->last_error)->toBeNull();
});

test('instance creation resumes failed instance when green credentials remain valid', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create();
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);

    $failed = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'external_ref' => 'lead_43',
        'status' => 'failed',
        'id_instance' => '770022689574',
        'api_token_instance' => 'valid-token',
        'last_error' => 'setSettings failed (HTTP 401): ',
    ]);

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Demo Acme',
            'externalRef' => 'lead_43',
            'purpose' => 'demo',
        ], idempotencyHeaders())
        ->assertAccepted()
        ->assertJsonPath('status', 'configuring');

    Bus::assertDispatched(ProvisionGreenInstanceJob::class);

    $failed->refresh();
    expect($failed->status)->toBe('configuring')
        ->and($failed->id_instance)->toBe('770022689574')
        ->and($failed->last_error)->toBeNull();
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

test('platform create is rejected with 422 when instance quota is exhausted', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'plan_slug' => 'starter',
        'max_instances' => 1,
    ]);
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
    ]);

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Second',
            'purpose' => 'production',
        ], idempotencyHeaders())
        ->assertStatus(422)
        ->assertJsonPath(
            'message',
            'Has alcanzado el límite de instancias WhatsApp de tu plan. Mejora tu cuenta para generar otra instancia.'
        );

    expect(Instancia::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(1);
    Bus::assertNotDispatched(ProvisionGreenInstanceJob::class);
});

test('idempotent externalRef replay does not hit quota when already at limit', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'plan_slug' => 'starter',
        'max_instances' => 1,
    ]);
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    $existing = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'external_ref' => 'same_ref',
        'status' => 'authorized',
    ]);

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Demo Acme',
            'externalRef' => 'same_ref',
        ], idempotencyHeaders())
        ->assertOk()
        ->assertJsonPath('publicId', $existing->public_id);

    expect(Instancia::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('failed instance retry by external ref is allowed when quota is exhausted', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'plan_slug' => 'starter',
        'max_instances' => 1,
    ]);
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    $failed = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'external_ref' => 'lead_42',
        'status' => 'failed',
        'id_instance' => '770022692540',
        'api_token_instance' => 'stale-token',
        'last_error' => 'Green delete failed: Partner deleteInstanceAccount failed: Not found',
    ]);

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Demo Acme',
            'externalRef' => 'lead_42',
            'purpose' => 'demo',
        ], idempotencyHeaders())
        ->assertAccepted()
        ->assertJsonPath('publicId', $failed->public_id)
        ->assertJsonPath('status', 'provisioning');

    Bus::assertDispatched(ProvisionGreenInstanceJob::class);
    expect(Instancia::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('failed instance counts toward quota for new create without same external ref', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'plan_slug' => 'starter',
        'max_instances' => 1,
    ]);
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'external_ref' => 'lead_42',
        'status' => 'failed',
        'last_error' => 'setSettings failed (HTTP 401): ',
    ]);

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Second',
            'externalRef' => 'lead_99',
            'purpose' => 'production',
        ], idempotencyHeaders())
        ->assertStatus(422)
        ->assertJsonPath(
            'message',
            'Has alcanzado el límite de instancias WhatsApp de tu plan. Mejora tu cuenta para generar otra instancia.'
        );

    expect(Instancia::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(1);
    Bus::assertNotDispatched(ProvisionGreenInstanceJob::class);
});

test('soft-deleted instance does not count toward quota', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'plan_slug' => 'starter',
        'max_instances' => 1,
    ]);
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
    ])->delete();

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Replacement',
            'purpose' => 'production',
        ], idempotencyHeaders())
        ->assertAccepted();

    Bus::assertDispatched(ProvisionGreenInstanceJob::class);
    expect(Instancia::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('null max_instances means unlimited', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'plan_slug' => 'empresa',
        'max_instances' => null,
    ]);
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    Instancia::factory()->create(['tenant_id' => $tenant->id, 'status' => 'authorized']);

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Another',
        ], idempotencyHeaders())
        ->assertAccepted();

    Bus::assertDispatched(ProvisionGreenInstanceJob::class);
});
