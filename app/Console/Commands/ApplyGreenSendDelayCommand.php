<?php

namespace App\Console\Commands;

use App\Models\Integration\Instancia;
use App\Services\GreenApi\GreenApiInstanceSettings;
use App\Services\GreenApi\InstanceClient;
use Illuminate\Console\Command;
use Throwable;

class ApplyGreenSendDelayCommand extends Command
{
    protected $signature = 'green:apply-send-delay
                            {--dry-run : List eligible instances without calling Green API}';

    protected $description = 'Apply delaySendMessagesMilliseconds=15000 to all eligible Green instances';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $baseUrl = (string) config('services.green_api.base_url');

        $candidates = Instancia::query()
            ->withoutGlobalScope('tenant')
            ->whereNotIn('status', ['deleted', 'deleting'])
            ->whereNotNull('id_instance')
            ->where('id_instance', '!=', '')
            ->whereNotNull('api_token_instance')
            ->orderBy('id')
            ->get();

        // Decrypt + filled() in PHP: encrypted column cannot be compared to '' reliably in SQL.
        $instances = $candidates->filter(function (Instancia $instancia): bool {
            return filled($instancia->id_instance) && filled($instancia->api_token_instance);
        })->values();

        if ($instances->isEmpty()) {
            $this->info('No eligible instances.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info($instances->count().' eligible instance(s) (dry-run, no HTTP).');
            foreach ($instances as $instancia) {
                $this->line('id='.$instancia->id.' id_instance='.$instancia->id_instance.' status='.$instancia->status);
            }

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($instances as $instancia) {
            $idInstance = (string) $instancia->id_instance;
            $token = (string) $instancia->api_token_instance;

            try {
                $client = new InstanceClient($baseUrl, $idInstance, $token);
                $client->setSettings([
                    'delaySendMessagesMilliseconds' => GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS,
                ]);
                $ok++;
                $this->line('OK instancia id='.$instancia->id);
            } catch (Throwable $e) {
                $failed++;
                $this->error('FAIL instancia id='.$instancia->id.': '.$e->getMessage());
            }
        }

        $this->info("Done. ok={$ok} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
