<?php

use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Services\TenantTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('cancel-commercial sets cancelled and revokes tokens without deleting instance', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'slug' => 'feat-cancel-commercial',
        'commercial_status' => 'active',
        'plan_slug' => 'starter',
    ]);
    $instance = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
    ]);
    $clientPlain = app(TenantTokenService::class)->issue($tenant, 'cliente-paid')->plainTextToken;

    $response = $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.cancel-commercial', $tenant->public_id), [
            'reason' => 'payment_failed_grace_expired',
        ], idempotencyHeaders());

    $response->assertOk()
        ->assertJsonPath('commercialStatus', 'cancelled')
        ->assertJsonPath('tenant.commercialStatus', 'cancelled')
        ->assertJsonPath('tokensRevoked', 1);

    $tenant->refresh();
    expect($tenant->commercial_status)->toBe('cancelled')
        ->and($tenant->meta['cancelled_at'] ?? null)->not->toBeNull();

    expect(PersonalAccessToken::findToken($clientPlain))->toBeNull();
    expect(Instancia::query()->whereKey($instance->id)->exists())->toBeTrue();
});

test('reactivate-commercial restores active and issues a new token', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'slug' => 'feat-reactivate-commercial',
        'commercial_status' => 'cancelled',
        'plan_slug' => 'starter',
        'meta' => [
            'cancelled_at' => now()->subDay()->toIso8601String(),
            'cancel_reason' => 'dunning',
        ],
    ]);
    $instancePublicId = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
    ])->public_id;

    $response = $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.reactivate-commercial', $tenant->public_id), [
            'tokenName' => 'membresia-reactivated',
        ], idempotencyHeaders());

    $response->assertCreated()
        ->assertJsonPath('commercialStatus', 'active')
        ->assertJsonPath('tenant.commercialStatus', 'active')
        ->assertJsonStructure(['token']);

    $newToken = $response->json('token');
    expect($newToken)->toBeString()->not->toBeEmpty();

    $tenant->refresh();
    expect($tenant->commercial_status)->toBe('active')
        ->and($tenant->meta['cancelled_at'] ?? 'gone')->toBe('gone')
        ->and($tenant->meta['reactivated_at'] ?? null)->not->toBeNull();

    expect(Instancia::query()->where('public_id', $instancePublicId)->exists())->toBeTrue();

    $this->app['auth']->forgetGuards();

    $this->withToken($newToken)
        ->postJson(route('api.v1.account.status'), [])
        ->assertOk()
        ->assertJsonPath('commercialStatus', 'active');
});
