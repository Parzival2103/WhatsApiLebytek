<?php

use App\Models\Integration\Webhook;

test('reports stale unprocessed rows and exits non-zero', function () {
    Webhook::factory()->create([
        'event_id' => sha1('old-unprocessed'),
        'processed_at' => null,
        'created_at' => now()->subMinutes(10),
    ]);

    $this->artisan('webhooks:check-unprocessed')
        ->expectsOutputToContain('1')
        ->assertExitCode(1);
});

test('ignores processed rows and recent unprocessed rows', function () {
    Webhook::factory()->processed()->create([
        'event_id' => sha1('processed'),
        'created_at' => now()->subMinutes(30),
    ]);

    Webhook::factory()->create([
        'event_id' => sha1('recent-unprocessed'),
        'processed_at' => null,
        'created_at' => now()->subMinute(),
    ]);

    $this->artisan('webhooks:check-unprocessed')
        ->assertExitCode(0);
});

test('honors a custom minutes threshold', function () {
    Webhook::factory()->create([
        'event_id' => sha1('three-min-old'),
        'processed_at' => null,
        'created_at' => now()->subMinutes(3),
    ]);

    // Default 5-minute threshold: not stale yet.
    $this->artisan('webhooks:check-unprocessed')->assertExitCode(0);

    // 2-minute threshold: now stale.
    $this->artisan('webhooks:check-unprocessed', ['--minutes' => 2])->assertExitCode(1);
});
