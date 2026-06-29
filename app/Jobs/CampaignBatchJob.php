<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stub for campaign batch processing (Green API vertical).
 *
 * Pattern for mass campaigns:
 * 1. Chunk recipient IDs (e.g. 500 per chunk) — never load all in memory.
 * 2. Build a Bus::batch() of CampaignBatchJob instances per chunk.
 * 3. Each job processes its chunk idempotently (message key dedupe).
 * 4. Horizon workers on the `campaigns` queue stay isolated from `transactional`.
 *
 * Example:
 *   Bus::batch($chunks->map(fn ($ids) => new CampaignBatchJob($campaignId, $ids)))
 *       ->onQueue('campaigns')
 *       ->dispatch();
 */
class CampaignBatchJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  list<string>  $recipientPublicIds
     */
    public function __construct(
        public readonly string $campaignPublicId,
        public readonly array $recipientPublicIds,
    ) {
        $this->onQueue('campaigns');
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Vertical placeholder: iterate chunk and dispatch TransactionalMessageJob or inline send.
    }
}
