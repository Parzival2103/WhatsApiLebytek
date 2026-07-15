<?php

use App\Models\Core\Tenant;
use App\Models\User;
use App\Services\TenantTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('platform can activate starter plan and old token is rejected', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'slug' => 'feat-activate-starter',
        'commercial_status' => 'demo',
        'plan_slug' => 'demo',
        'messages_monthly_limit' => 100,
        'demo_expires_at' => now()->addDays(10),
    ]);

    $oldPlain = app(TenantTokenService::class)->issue($tenant, 'cliente-demo')->plainTextToken;

    $response = $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.activate-plan', $tenant->public_id), [
            'planSlug' => 'starter',
            'billingCycle' => 'monthly',
            'orderExternalRef' => '01JXORDERFEAT1',
            'tokenName' => 'cliente-paid-starter',
        ], idempotencyHeaders());

    $response->assertCreated()
        ->assertJsonPath('plan.slug', 'starter')
        ->assertJsonPath('plan.messagesMonthlyLimit', 5000)
        ->assertJsonPath('tenant.commercialStatus', 'active')
        ->assertJsonPath('tenant.planSlug', 'starter')
        ->assertJsonStructure(['tenant' => ['publicId'], 'token', 'plan']);

    $newToken = $response->json('token');
    expect($newToken)->toBeString()->not->toBeEmpty();
    expect(PersonalAccessToken::findToken($oldPlain))->toBeNull();

    $this->app['auth']->forgetGuards();

    $this->withToken($newToken)
        ->postJson(route('api.v1.account.status'), [])
        ->assertOk()
        ->assertJsonPath('commercialStatus', 'active')
        ->assertJsonPath('plan.slug', 'starter');
});

test('tenant token cannot activate plan', function () {
    $tenant = Tenant::factory()->create(['slug' => 'feat-activate-denied']);
    $user = User::factory()->forTenant($tenant)->create();
    $user->givePermissionTo('tenants.gestionar');
    $tenantToken = $user->createToken('tenant', ['tenants.gestionar'])->plainTextToken;

    $this->withToken($tenantToken)
        ->postJson(route('api.v1.tenants.activate-plan', $tenant->public_id), [
            'planSlug' => 'starter',
            'billingCycle' => 'monthly',
            'orderExternalRef' => '01JXORDERDENY1',
        ], idempotencyHeaders())
        ->assertForbidden();
});

test('starter rejects client-supplied messagesMonthlyLimit with 422', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create(['slug' => 'feat-activate-no-override']);

    $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.activate-plan', $tenant->public_id), [
            'planSlug' => 'starter',
            'billingCycle' => 'monthly',
            'orderExternalRef' => '01JXORDERNOOV1',
            'messagesMonthlyLimit' => 999999,
        ], idempotencyHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['messagesMonthlyLimit']);
});

test('empresa accepts custom messagesMonthlyLimit', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create(['slug' => 'feat-activate-empresa']);

    $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.activate-plan', $tenant->public_id), [
            'planSlug' => 'empresa',
            'billingCycle' => 'annual',
            'orderExternalRef' => '01JXORDEREF1',
            'messagesMonthlyLimit' => 250000,
        ], idempotencyHeaders())
        ->assertCreated()
        ->assertJsonPath('plan.messagesMonthlyLimit', 250000)
        ->assertJsonPath('tenant.messagesMonthlyLimit', 250000);
});

test('idempotency key replay returns same body', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create(['slug' => 'feat-activate-idem-key']);
    $headers = idempotencyHeaders();

    $payload = [
        'planSlug' => 'business',
        'billingCycle' => 'monthly',
        'orderExternalRef' => '01JXORDERIDEMKEY1',
    ];

    $first = $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.activate-plan', $tenant->public_id), $payload, $headers)
        ->assertCreated();

    $second = $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.activate-plan', $tenant->public_id), $payload, $headers)
        ->assertCreated();

    expect($second->json())->toEqual($first->json());
});

test('semantic replay with different idempotency key returns 200 and null token', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'slug' => 'feat-activate-semantic',
        'commercial_status' => 'demo',
        'plan_slug' => 'demo',
        'messages_monthly_limit' => 100,
    ]);

    $payload = [
        'planSlug' => 'starter',
        'billingCycle' => 'monthly',
        'orderExternalRef' => '01JXORDERSEMANTIC1',
        'tokenName' => 'cliente-paid-starter',
    ];

    $first = $this->withToken($platformToken)
        ->postJson(
            route('api.v1.tenants.activate-plan', $tenant->public_id),
            $payload,
            ['Idempotency-Key' => 'activate-semantic-first-'.uniqid()],
        );

    $first->assertCreated()
        ->assertJsonPath('plan.slug', 'starter')
        ->assertJsonPath('token', fn ($token) => is_string($token) && $token !== '');

    $issuedToken = $first->json('token');

    $second = $this->withToken($platformToken)
        ->postJson(
            route('api.v1.tenants.activate-plan', $tenant->public_id),
            $payload,
            ['Idempotency-Key' => 'activate-semantic-second-'.uniqid()],
        );

    $second->assertOk()
        ->assertJsonPath('plan.slug', 'starter')
        ->assertJsonPath('plan.messagesMonthlyLimit', 5000)
        ->assertJsonPath('tenant.commercialStatus', 'active')
        ->assertJsonPath('token', null);

    expect(PersonalAccessToken::findToken($issuedToken))->not->toBeNull();
});
