<?php

namespace App\Console\Commands;

use App\Models\Integration\Webhook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckUnprocessedWebhooksCommand extends Command
{
    protected $signature = 'webhooks:check-unprocessed {--minutes=5 : Age threshold in minutes}';

    protected $description = 'Alert when int_webhooks rows remain unprocessed past a threshold';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $threshold = now()->subMinutes($minutes);

        $count = Webhook::query()
            ->whereNull('processed_at')
            ->where('created_at', '<', $threshold)
            ->count();

        if ($count === 0) {
            $this->info("No unprocessed webhooks older than {$minutes}m.");

            return self::SUCCESS;
        }

        $message = "{$count} unprocessed webhook(s) older than {$minutes}m.";

        Log::warning('Unprocessed webhook backlog detected.', [
            'count' => $count,
            'minutes' => $minutes,
        ]);

        $this->warn($message);

        return self::FAILURE;
    }
}
