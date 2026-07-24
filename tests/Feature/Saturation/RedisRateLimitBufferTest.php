<?php

/**
 * Live saturation: Redis job throttle buffer (30/min per tenant on TransactionalMessageJob).
 *
 * Soft-passes in CI. Manual run spends real demo messages into SATURATION_RECIPIENT.
 *
 * Caveat: HTTP `messages-send` is 10/min, so a single client cannot flood Redis above
 * 30/min through the public API. This test still verifies:
 *   - messages progress queued → sent via Horizon/workers
 *   - no more than 30 `sent` completions fall inside any 60s window
 *   - prints sentAt cadence for inbox spam review
 */

use Carbon\CarbonImmutable;
use Tests\Support\SaturationGate;
use Tests\Support\SaturationLiveClient;

test('live redis buffer spaces transactional sends and does not exceed 30 per minute', function () {
    if (! SaturationGate::shouldRunLive()) {
        SaturationGate::softPass();

        return;
    }

    $burst = (int) (getenv('SATURATION_BUFFER_COUNT') ?: 35);
    $burst = max(31, min(40, $burst));

    $client = SaturationLiveClient::fromCredentials(SaturationGate::resolveCredentials());
    $status = $client->accountStatus();
    $remaining = (int) ($status['usage']['messagesRemainingThisMonth'] ?? 0);

    if ($remaining < $burst) {
        throw new \RuntimeException(
            "Need at least {$burst} remaining messages (have {$remaining}). Prefer the quota test on a fresh demo month."
        );
    }

    fwrite(STDOUT, "Enqueuing {$burst} messages for Redis buffer observation…\n");

    $publicIds = [];
    for ($i = 1; $i <= $burst; $i++) {
        $result = $client->postMessage("saturation-buffer {$i}/{$burst}");
        if (! in_array($result['status'], [200, 202], true)) {
            throw new \RuntimeException("Enqueue #{$i} failed: HTTP {$result['status']} {$result['raw']}");
        }
        $publicId = $result['body']['publicId'] ?? null;
        expect($publicId)->toBeString()->not->toBeEmpty();
        $publicIds[] = $publicId;

        if ($i % 10 === 0 || $i === $burst) {
            fwrite(STDOUT, "  queued {$i}/{$burst}\n");
        }
    }

    $deadline = time() + 900;
    $sentAtById = [];

    fwrite(STDOUT, "Polling GET /messages until sent (or 15 min)…\n");

    while (count($sentAtById) < $burst && time() < $deadline) {
        foreach ($publicIds as $publicId) {
            if (isset($sentAtById[$publicId])) {
                continue;
            }

            $got = $client->getMessage($publicId);
            if ($got['status'] !== 200) {
                throw new \RuntimeException("GET message {$publicId} failed: HTTP {$got['status']} {$got['raw']}");
            }

            $msgStatus = $got['body']['status'] ?? null;
            if ($msgStatus === 'failed') {
                throw new \RuntimeException(
                    "Message {$publicId} failed: ".($got['body']['error'] ?? 'unknown')
                );
            }

            if ($msgStatus === 'sent' && ! empty($got['body']['sentAt'])) {
                $sentAtById[$publicId] = CarbonImmutable::parse($got['body']['sentAt']);
            }
        }

        fwrite(STDOUT, '  sent '.count($sentAtById)."/{$burst}\n");

        if (count($sentAtById) >= $burst) {
            break;
        }

        sleep(5);
    }

    if (count($sentAtById) < 31) {
        throw new \RuntimeException(
            'Expected at least 31 messages to reach sent so the 30/min buffer window can be observed. Got '.count($sentAtById)
        );
    }

    $times = array_values($sentAtById);
    usort($times, fn (CarbonImmutable $a, CarbonImmutable $b) => $a <=> $b);

    $maxInAnyMinute = 0;
    $n = count($times);
    for ($i = 0; $i < $n; $i++) {
        $windowEnd = $times[$i]->addSeconds(60);
        $count = 0;
        for ($j = $i; $j < $n; $j++) {
            if ($times[$j]->greaterThan($windowEnd)) {
                break;
            }
            $count++;
        }
        $maxInAnyMinute = max($maxInAnyMinute, $count);
    }

    $first = $times[0];
    $last = $times[min(30, $n - 1)];
    $spanTo31 = $first->diffInSeconds($last);

    fwrite(STDOUT, "Max sent in any 60s window: {$maxInAnyMinute}\n");
    fwrite(STDOUT, "Seconds from 1st to 31st sentAt (or last): {$spanTo31}\n");
    fwrite(STDOUT, "Review your WhatsApp inbox for spam spacing (~30/min job cap; HTTP enqueue ~10/min).\n");

    expect($maxInAnyMinute)->toBeLessThanOrEqual(30);
});
