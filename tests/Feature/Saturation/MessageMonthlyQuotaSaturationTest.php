<?php

/**
 * Live saturation: monthly message quota.
 *
 * Soft-passes in CI. Manual:
 *   RUN_SATURATION_TESTS=1 + SATURATION_DEMO_TOKEN (+ instance/recipient)
 *
 * Fill size = min(100, remaining):
 *   remaining 59  → enqueue 59, then 5× monthly-quota 429
 *   remaining 243 → enqueue 100 only, then 5 still accepted (under real limit)
 *   remaining 0   → skip fill, only 5× monthly-quota 429
 *
 * Note: product HTTP limiter `messages-send` is 10/min — expect ~1 min pause each batch of 10.
 */

use Tests\Support\SaturationGate;
use Tests\Support\SaturationLiveClient;

test('live demo monthly quota accepts fill then rejects overflow with 429', function () {
    if (! SaturationGate::shouldRunLive()) {
        SaturationGate::softPass();

        return;
    }

    $client = SaturationLiveClient::fromCredentials(SaturationGate::resolveCredentials());
    $status = $client->accountStatus();

    $limit = $status['usage']['messagesLimitThisMonth'] ?? null;
    $remaining = $status['usage']['messagesRemainingThisMonth'] ?? null;
    $sent = $status['usage']['messagesSentThisMonth'] ?? null;

    expect($limit)->not->toBeNull();
    expect($remaining)->toBeInt();

    $remaining = (int) $remaining;
    $maxFill = 100;
    $toEnqueue = min($maxFill, max(0, $remaining));
    $drainsMonthlyQuota = $toEnqueue === $remaining;

    fwrite(
        STDOUT,
        "account usage: sent={$sent} limit={$limit} remaining={$remaining} → fill={$toEnqueue}".
        ($drainsMonthlyQuota ? ' (will assert 5× monthly 429)' : ' (remaining > 100; +5 stay under quota)').
        " — HTTP throttle ~10/min\n"
    );

    for ($i = 1; $i <= $toEnqueue; $i++) {
        $result = $client->postMessage("saturation-quota {$i}/{$toEnqueue}");
        if (! in_array($result['status'], [200, 202], true)) {
            throw new \RuntimeException(
                "Expected accept while filling quota (#{$i}): HTTP {$result['status']} {$result['raw']}"
            );
        }
        expect($result['body']['status'] ?? null)->toBe('queued');

        if ($i % 10 === 0 || $i === $toEnqueue) {
            fwrite(STDOUT, "  queued {$i}/{$toEnqueue}\n");
        }
    }

    $overflowOk = 0;
    for ($i = 1; $i <= 5; $i++) {
        $result = $client->postMessage("saturation-quota overflow {$i}");

        if ($drainsMonthlyQuota) {
            if (! $client->isMonthlyQuotaExceeded($result)) {
                throw new \RuntimeException(
                    "Overflow #{$i} must be monthly quota 429, got HTTP {$result['status']}: {$result['raw']}"
                );
            }
            fwrite(STDOUT, "  overflow #{$i} → 429 monthly quota\n");
        } else {
            if (! in_array($result['status'], [200, 202], true)) {
                throw new \RuntimeException(
                    "Overflow #{$i} should still accept (remaining was > {$maxFill}), got HTTP {$result['status']}: {$result['raw']}"
                );
            }
            fwrite(STDOUT, "  post-fill #{$i} → HTTP {$result['status']} (under monthly limit)\n");
        }

        $overflowOk++;
    }

    expect($overflowOk)->toBe(5);
});
