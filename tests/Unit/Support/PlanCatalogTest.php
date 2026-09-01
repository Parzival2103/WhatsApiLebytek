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

test('catalog includes max_instances per slug', function () {
    expect(PlanCatalog::definition('demo')['max_instances'])->toBe(1)
        ->and(PlanCatalog::definition('starter')['max_instances'])->toBe(1)
        ->and(PlanCatalog::definition('business')['max_instances'])->toBe(3)
        ->and(PlanCatalog::definition('empresa')['max_instances'])->toBeNull();
});

test('resolveMaxInstances returns catalog values and ignores override for starter', function () {
    expect(PlanCatalog::resolveMaxInstances('starter', 99))->toBe(1)
        ->and(PlanCatalog::resolveMaxInstances('business', null))->toBe(3);
});

test('resolveMaxInstances allows unlimited empresa without override', function () {
    expect(PlanCatalog::resolveMaxInstances('empresa', null))->toBeNull();
});

test('resolveMaxInstances accepts empresa override', function () {
    expect(PlanCatalog::resolveMaxInstances('empresa', 10))->toBe(10);
});

test('resolveMaxInstances rejects empresa override below one', function () {
    expect(fn () => PlanCatalog::resolveMaxInstances('empresa', 0))
        ->toThrow(\InvalidArgumentException::class);
});
