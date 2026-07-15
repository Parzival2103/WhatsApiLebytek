<?php

use App\Jobs\CampaignBatchJob;
use App\Jobs\Middleware\RateLimitedWithRedis;
use App\Jobs\TransactionalMessageJob;
use App\Models\Core\Tenant;
use App\Models\Integration\Mensaje;
use App\Support\PlanRateResolver;

test('horizon defines isolated supervisors for default transactional and campaigns queues', function () {
    $defaults = config('horizon.defaults');

    expect($defaults)->toHaveKeys(['supervisor-default', 'supervisor-transactional', 'supervisor-campaigns'])
        ->and($defaults['supervisor-default']['queue'])->toBe(['default'])
        ->and($defaults['supervisor-transactional']['queue'])->toBe(['transactional'])
        ->and($defaults['supervisor-campaigns']['queue'])->toBe(['campaigns']);
});

test('stub jobs dispatch to the correct queues', function () {
    $transactional = new TransactionalMessageJob(1);
    $campaign = new CampaignBatchJob('campaign-ulid', ['recipient-a', 'recipient-b']);

    expect($transactional->queue)->toBe('transactional')
        ->and($campaign->queue)->toBe('campaigns');
});

test('transactional job redis throttle uses starter plan job rate', function () {
    $tenant = Tenant::factory()->create([
        'slug' => 'job-rate-starter',
        'plan_slug' => 'starter',
    ]);
    $mensaje = Mensaje::factory()->create(['tenant_id' => $tenant->id]);
    $job = new TransactionalMessageJob($mensaje->id);

    $middleware = $job->middleware();
    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(RateLimitedWithRedis::class);

    $maxAttempts = (new ReflectionProperty(RateLimitedWithRedis::class, 'maxAttempts'))
        ->getValue($middleware[0]);

    expect($maxAttempts)->toBe(60)
        ->and($maxAttempts)->toBe(PlanRateResolver::jobSendPerMinute($tenant));
});

test('transactional job redis throttle falls back to demo rate when plan slug missing', function () {
    $tenant = Tenant::factory()->create([
        'slug' => 'job-rate-demo-fallback',
        'plan_slug' => null,
    ]);
    $mensaje = Mensaje::factory()->create(['tenant_id' => $tenant->id]);
    $job = new TransactionalMessageJob($mensaje->id);

    $middleware = $job->middleware()[0];
    $maxAttempts = (new ReflectionProperty(RateLimitedWithRedis::class, 'maxAttempts'))
        ->getValue($middleware);

    expect($maxAttempts)->toBe(30)
        ->and($maxAttempts)->toBe(PlanRateResolver::jobSendPerMinute($tenant));
});
