<?php

use App\Support\PlanCatalog;
use Tests\TestCase;

uses(TestCase::class);

test('catalog resolves starter monthly limit and rates', function () {
    $plan = PlanCatalog::definition('starter');

    expect($plan)->not->toBeNull()
        ->and($plan['name'])->toBe('Starter')
        ->and($plan['messages_monthly_limit'])->toBe(5000)
        ->and($plan['http_send_per_minute'])->toBe(30)
        ->and($plan['job_send_per_minute'])->toBe(60);
});

test('unknown slug returns null definition', function () {
    expect(PlanCatalog::definition('nope'))->toBeNull();
});

test('resolveMessagesMonthlyLimit ignores override for starter', function () {
    expect(PlanCatalog::resolveMessagesMonthlyLimit('starter', 999999))->toBe(5000);
});

test('resolveMessagesMonthlyLimit accepts empresa override in range', function () {
    expect(PlanCatalog::resolveMessagesMonthlyLimit('empresa', 250000))->toBe(250000);
});

test('resolveMessagesMonthlyLimit rejects empresa override below min', function () {
    expect(fn () => PlanCatalog::resolveMessagesMonthlyLimit('empresa', 1))
        ->toThrow(\InvalidArgumentException::class);
});
