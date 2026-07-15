<?php

use App\Models\Core\Tenant;
use App\Support\PlanRateResolver;
use Tests\TestCase;

uses(TestCase::class);

test('resolver uses starter http and job rates', function () {
    $tenant = Tenant::factory()->make(['plan_slug' => 'starter']);

    expect(PlanRateResolver::httpSendPerMinute($tenant))->toBe(30)
        ->and(PlanRateResolver::jobSendPerMinute($tenant))->toBe(60);
});

test('resolver falls back to demo when slug missing', function () {
    $tenant = Tenant::factory()->make(['plan_slug' => null]);

    expect(PlanRateResolver::httpSendPerMinute($tenant))->toBe(10)
        ->and(PlanRateResolver::jobSendPerMinute($tenant))->toBe(30);
});
