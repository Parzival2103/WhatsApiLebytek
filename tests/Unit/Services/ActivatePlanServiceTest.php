<?php

use App\Models\Core\Tenant;
use App\Services\ActivatePlanService;
use App\Services\TenantTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('activate upgrades demo tenant to starter and issues token', function () {
    $tenant = Tenant::factory()->create([
        'slug' => 'activate-starter',
        'commercial_status' => 'demo',
        'plan_slug' => 'demo',
        'plan_name' => 'Demo',
        'messages_monthly_limit' => 100,
        'demo_expires_at' => now()->addDays(20),
    ]);

    $demoToken = app(TenantTokenService::class)->issue($tenant, 'cliente-demo');
    $demoPlain = $demoToken->plainTextToken;

    $result = app(ActivatePlanService::class)->activate($tenant, [
        'planSlug' => 'starter',
        'billingCycle' => 'monthly',
        'orderExternalRef' => '01JXORDERSTARTER1',
        'tokenName' => 'cliente-paid-starter',
    ]);

    expect($result['created'])->toBeTrue()
        ->and($result['token'])->toBeString()->not->toBeEmpty()
        ->and($result['plan']['slug'])->toBe('starter')
        ->and($result['plan']['messagesMonthlyLimit'])->toBe(5000);

    $tenant->refresh();
    expect($tenant->commercial_status)->toBe('active')
        ->and($tenant->plan_slug)->toBe('starter')
        ->and($tenant->plan_name)->toBe('Starter')
        ->and($tenant->messages_monthly_limit)->toBe(5000)
        ->and($tenant->demo_expires_at)->toBeNull()
        ->and($tenant->meta['billing_cycle'])->toBe('monthly')
        ->and($tenant->meta['activated_order_ref'])->toBe('01JXORDERSTARTER1')
        ->and($tenant->meta['activated_at'])->not->toBeEmpty();

    expect(\Laravel\Sanctum\PersonalAccessToken::findToken($demoPlain))->toBeNull();
});

test('activate is semantically idempotent for same slug and order ref', function () {
    $tenant = Tenant::factory()->create([
        'slug' => 'activate-idem',
        'commercial_status' => 'demo',
        'messages_monthly_limit' => 100,
    ]);

    $service = app(ActivatePlanService::class);
    $first = $service->activate($tenant, [
        'planSlug' => 'starter',
        'billingCycle' => 'monthly',
        'orderExternalRef' => '01JXORDERIDEM1',
    ]);

    $second = $service->activate($tenant->fresh(), [
        'planSlug' => 'starter',
        'billingCycle' => 'monthly',
        'orderExternalRef' => '01JXORDERIDEM1',
    ]);

    expect($first['created'])->toBeTrue()
        ->and($second['created'])->toBeFalse()
        ->and($second['token'])->toBeNull()
        ->and(\Laravel\Sanctum\PersonalAccessToken::findToken($first['token']))->not->toBeNull();
});

test('starter ignores messagesMonthlyLimit override', function () {
    $tenant = Tenant::factory()->create([
        'slug' => 'activate-override',
        'commercial_status' => 'demo',
        'messages_monthly_limit' => 100,
    ]);

    $result = app(ActivatePlanService::class)->activate($tenant, [
        'planSlug' => 'starter',
        'billingCycle' => 'monthly',
        'orderExternalRef' => '01JXORDEROVERRIDE1',
        'messagesMonthlyLimit' => 999999,
    ]);

    expect($result['plan']['messagesMonthlyLimit'])->toBe(5000)
        ->and($tenant->fresh()->messages_monthly_limit)->toBe(5000);
});

test('empresa requires messagesMonthlyLimit', function () {
    $tenant = Tenant::factory()->create(['slug' => 'activate-empresa']);

    expect(fn () => app(ActivatePlanService::class)->activate($tenant, [
        'planSlug' => 'empresa',
        'billingCycle' => 'annual',
        'orderExternalRef' => '01JXORDEREMP1',
    ]))->toThrow(ValidationException::class);
});

test('empresa accepts custom limit', function () {
    $tenant = Tenant::factory()->create(['slug' => 'activate-empresa-ok']);

    $result = app(ActivatePlanService::class)->activate($tenant, [
        'planSlug' => 'empresa',
        'billingCycle' => 'annual',
        'orderExternalRef' => '01JXORDEREMP2',
        'messagesMonthlyLimit' => 250000,
    ]);

    expect($result['created'])->toBeTrue()
        ->and($tenant->fresh()->messages_monthly_limit)->toBe(250000);
});
