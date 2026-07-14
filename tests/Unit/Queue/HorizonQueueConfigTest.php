<?php

use App\Jobs\CampaignBatchJob;
use App\Jobs\TransactionalMessageJob;

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

test('transactional job registers redis rate limit middleware', function () {
    $mensaje = \App\Models\Integration\Mensaje::factory()->create();
    $job = new TransactionalMessageJob($mensaje->id);

    expect($job->middleware())->toHaveCount(1);
});
