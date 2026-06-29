<?php

namespace App\Jobs;

use App\Jobs\Middleware\RateLimitedWithRedis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stub for transactional WhatsApp messages (Green API vertical).
 * Dispatch individually or in small batches — never load full recipient lists in memory.
 */
class TransactionalMessageJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantPublicId,
        public readonly string $recipient,
        public readonly string $payloadHash,
    ) {
        $this->onQueue('transactional');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new RateLimitedWithRedis("green-api:tenant:{$this->tenantPublicId}", maxAttempts: 30, decaySeconds: 60),
        ];
    }

    public function handle(): void
    {
        // Vertical placeholder: send single transactional message via Green API adapter.
    }
}
